<?php

namespace App\Services\Company;

use App\Http\Resources\Company\CompanyInquiryResource;
use App\Models\Company\CompanyInquiry;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Operations\MutationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CompanyConversations
{
    public function __construct(private CompanyAccess $access, private AuditLogger $audit) {}

    public function token(CompanyInquiry $inquiry): string
    {
        return $inquiry->id.'.'.hash_hmac('sha256', 'company-inquiry:'.$inquiry->id.':'.$inquiry->idempotency_key, config('app.key'));
    }

    public function find(string $token, bool $lock = false): CompanyInquiry
    {
        $inquiry = CompanyInquiry::query()->when($lock, fn ($query) => $query->lockForUpdate())->find(explode('.', $token, 2)[0]);
        abort_unless($inquiry && hash_equals($this->token($inquiry), $token), 404, 'رابط متابعة الرسالة غير صالح.');

        return $inquiry;
    }

    public function detail(CompanyInquiry $inquiry, Request $request, bool $public = false): array
    {
        $record = (new CompanyInquiryResource($inquiry))->resolve($request);
        if ($public) {
            unset($record['contact'], $record['reviewed_at']);
        } else {
            $record['tracking_token'] = $this->token($inquiry);
        }
        $record['messages'] = DB::table('company_inquiry_messages')->where('inquiry_id', $inquiry->id)->orderByDesc('id')->limit(100)->get(['id', 'sender', 'body', 'created_at'])->reverse()->values()->map(fn ($row): array => ['id' => $row->id, 'sender' => $row->sender, 'body' => $row->body, 'time' => Carbon::parse($row->created_at)->toISOString()])->all();

        return $record;
    }

    public function reply(?User $actor, array $data, Request $request, ?int $id = null): CompanyInquiry
    {
        if ($actor) {
            $this->access->requireInbox($actor, true);
        }

        return DB::transaction(function () use ($actor, $data, $request, $id): CompanyInquiry {
            $guard = app(MutationGuard::class);
            if ($actor) {
                $guard->lock($actor, [], 'support.reply');
            } else {
                $guard->lockRuntime();
            }
            $inquiry = $actor ? CompanyInquiry::lockForUpdate()->findOrFail($id) : $this->find($data['token'], true);
            $sender = $actor ? 'staff' : 'visitor';
            $hash = hash('sha256', json_encode(['body' => trim($data['body']), 'sender' => $sender, 'user_id' => $actor?->id], JSON_THROW_ON_ERROR));
            $old = DB::table('company_inquiry_messages')->where('inquiry_id', $inquiry->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_hash, $hash), 409, 'معرف الرد مستخدم لبيانات مختلفة.');

                return $inquiry;
            }
            if ($actor) {
                abort_unless($inquiry->version === $data['version'], 409, 'تغيرت المحادثة؛ حدّث الرسالة قبل الرد.');
            }
            DB::table('company_inquiry_messages')->insert(['inquiry_id' => $inquiry->id, 'user_id' => $actor?->id, 'sender' => $sender, 'body' => trim($data['body']), 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            $inquiry->update(['status' => $actor ? 'followed' : 'new', 'version' => $inquiry->version + 1] + ($actor ? ['reviewer_id' => $actor->id, 'reviewed_at' => now()] : []));
            if ($actor) {
                $this->audit->record('support.site-inquiry.reply', $request, $actor, $actor->membership->account_id, ['inquiry_id' => $inquiry->id, 'version' => $inquiry->version]);
            }

            return $inquiry;
        });
    }
}
