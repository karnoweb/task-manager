<?php

namespace Karnoweb\TaskManager\UI\Crm;

use Karnoweb\TaskManager\Exceptions\DomainError;

/** Register explicitly in the CRM host; no dependency is loaded by the package core. */
final readonly class LegacyWriteGuard
{
    public function __construct(private HostAccess $access) {}
    public function saving(object $model): void { $this->check($model); }
    public function deleting(object $model): void { $this->check($model); }
    public function restoring(object $model): void { $this->check($model); }
    private function check(object $model): void
    {
        $db=$model->getConnection(); $scope=$this->access->scopeFor($model);
        $source = $model instanceof \Karnoweb\Crm\Models\Deal ? 'crm.deals' : 'crm.tasks';
        // Entire inventoried source is frozen after cutover, including creation of new legacy rows.
        if ($db->table('tm_host_cutovers')->where('scope_key',$scope)->where('source_key',$source)->where('mode','active')->lockForUpdate()->exists()) { throw new DomainError('legacy_writer_disabled'); }
    }
}
