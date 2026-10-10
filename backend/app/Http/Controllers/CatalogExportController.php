<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogRequest;
use App\Models\CatalogProvider;
use App\Services\AuditLogger;
use App\Services\CatalogAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class CatalogExportController extends Controller
{
    public function __invoke(CatalogRequest $request, string $kind, CatalogAccess $access, AuditLogger $audit): JsonResponse
    {
        abort_unless(in_array($kind, ['products', 'providers'], true), 404);
        $access->require($request->user(), $kind.'.export', $kind === 'providers');
        if ($kind === 'products') {
            $access->require($request->user(), 'data.cost');
        }
        $query = $kind === 'providers' ? CatalogProvider::query() : $access->products($request->user())->with('provider');
        $filters = $request->validated();
        foreach ($kind === 'providers' ? ['supplier', 'connection'] : ['kind', 'provider'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key === 'provider' ? 'provider_id' : $key, $filters[$key]);
            }
        }
        if (! empty($filters['state'])) {
            $query->where('status', $filters['state'] === 'active' ? 'active' : 'disabled');
        }
        if (! empty($filters['query'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['query']).'%';
            $columns = $kind === 'providers' ? ['id', 'name', 'supplier', 'connection'] : ['id', 'name', 'kind', 'currency', 'face_value', 'minimum_price', 'daily_quantity', 'daily_amount', 'import_codes', 'receipt_header', 'receipt_footer', 'allowed_cities'];
            $query->where(function ($search) use ($columns, $term): void {
                foreach ($columns as $column) {
                    $search->orWhereRaw($column." LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], 'Asia/Baghdad')->startOfDay()->utc());
        } if (! empty($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'], 'Asia/Baghdad')->startOfDay()->addDay()->utc());
        }
        abort_if((clone $query)->count() > 10000, 422, 'حدد الفلاتر لتصدير 10000 سجل أو أقل.');
        $rows = $kind === 'providers' ? $query->orderBy('id')->get() : $query->orderBy('display_order')->orderBy('id')->get();
        $headings = $kind === 'providers' ? ['المعرف', 'اسم الشركة', 'المجهز', 'نوع الربط', 'الحالة'] : ['المعرف', 'معرّفات الفئة في ملفات الطلبيات', 'الصورة', 'الفئة', 'النوع', 'الشركة', 'القيمة الاسمية', 'عملة القيمة الاسمية', 'أقل سعر بيع مسموح', 'حد الكمية اليومي', 'الحد المالي اليومي', 'رمز الشحن / التفعيل', 'تاريخ الانتهاء', 'الرقم التسلسلي', 'رمز التحقق', 'الرقم المرجعي', 'المحافظات المسموحة', 'عرض الوصل', 'النص أعلى البطاقة', 'النص أسفل البطاقة', 'الترتيب', 'الحالة'];
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $headings, ',', '"', '');
        foreach ($rows as $row) {
            if ($kind === 'providers') {
                $values = [$row->id, $row->name, $row->supplier, $row->connection, $row->status === 'active' ? 'مفعلة' : 'معطلة'];
            } else {
                $values = [$row->id, implode(', ', $row->import_codes), $row->image_path ? '/api/v1/catalog/products/'.$row->id.'/image' : '', $row->name, $row->kind, $row->provider->name, $row->face_value, $row->currency, $row->minimum_price, $row->daily_quantity ?? '', $row->daily_amount ?? ''];
                foreach (['pin', 'expiry', 'serial', 'cvc', 'reference'] as $key) {
                    $values[] = ($row->field_policy[$key] ?? 'unused') === 'required' ? 'مطلوب' : 'غير مستخدم';
                } array_push($values, implode('، ', $row->allowed_cities) ?: 'الكل', $row->receipt_width.' mm', $row->receipt_header, $row->receipt_footer, $row->display_order, $row->status === 'active' ? 'مفعلة' : 'معطلة');
            }
            $values = array_map(static function ($value): string {
                $text = (string) $value;

                return preg_match('/^[\s]*[=+@\-]/u', $text) ? "'".$text : $text;
            }, $values);
            fputcsv($stream, $values, ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $audit->record($kind.'.export', $request, $request->user(), null, ['count' => $rows->count()]);

        return response()->json(['data' => ['filename' => 'masal-'.$kind.'.csv', 'csv' => $csv, 'count' => $rows->count()]], 200, ['Cache-Control' => 'private, no-store']);
    }
}
