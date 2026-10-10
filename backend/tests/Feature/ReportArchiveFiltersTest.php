<?php

namespace Tests\Feature;

use App\Models\Operations\AccountArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class ReportArchiveFiltersTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    public function test_archived_account_filters_work_only_in_archive_rows_and_single_archive_export(): void
    {
        $f = $this->supportFixture();
        $f['pos']->forceFill(['archived_at' => now(), 'status' => 'disabled'])->save();
        $record = AccountArchive::factory()->create(['account_id' => $f['pos']->id, 'actor_id' => $f['admin']->id]);
        $this->supportGrant($f['agent'], 'agents.archiveView');
        $this->asPortalUser($f['agent']);
        $filter = '?pos_id='.$f['pos']->id;
        $this->getJson('/api/v1/reports/sections/network-archive/rows'.$filter)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $record->id);
        $this->getJson('/api/v1/reports/sections/network-archive/rows/'.$record->id.$filter)->assertOk()->assertJsonPath('data.id', $record->id);
        $this->postJson('/api/v1/reports/export', ['section_ids' => ['network-archive'], 'pos_id' => $f['pos']->id])->assertOk()->assertJsonCount(1, 'data.sections.0.rows');
        $this->getJson('/api/v1/reports/sections/network/rows'.$filter)->assertNotFound();
        $this->postJson('/api/v1/reports/export', ['section_ids' => ['network-archive', 'network'], 'pos_id' => $f['pos']->id])->assertNotFound();
    }

    public function test_archive_filters_cannot_expand_foreign_scope_or_bypass_archive_permission(): void
    {
        $f = $this->supportFixture();
        $f['pos']->forceFill(['archived_at' => now(), 'status' => 'disabled'])->save();
        $f['foreign']->forceFill(['archived_at' => now(), 'status' => 'disabled'])->save();
        $this->supportGrant($f['agent'], 'agents.archiveView');
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/reports/sections/network-archive/rows?agent_id='.$f['foreign']->id)->assertNotFound();
        $this->supportGrant($f['agent'], 'agents.archiveView', false);
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/reports/sections/network-archive/rows?pos_id='.$f['pos']->id)->assertForbidden();
    }
}
