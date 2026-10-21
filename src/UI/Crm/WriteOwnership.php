<?php

namespace Karnoweb\TaskManager\UI\Crm;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class WriteOwnership
{
    public static function constrainTasks(Connection $db, Builder $query): void
    {
        $query->whereNotExists(function (Builder $q) {
            $q->selectRaw('1')->from('tm_host_imports')->join('tm_host_cutovers','tm_host_cutovers.id','=','tm_host_imports.cutover_id')
                ->whereColumn('tm_host_imports.task_id','tm_tasks.id')->where('tm_host_cutovers.mode','!=','active');
        });
    }
    public static function assertHost(Connection $db, string $scope, string $type, string $id): void
    {
        $row = $db->table('tm_host_imports')->join('tm_host_cutovers','tm_host_cutovers.id','=','tm_host_imports.cutover_id')
            ->where('scope_key',$scope)->where('legacy_type',$type)->where('legacy_id',$id)->where('mode','active')->lockForUpdate()->first();
        if ($row) { throw new DomainError('legacy_writer_disabled'); }
    }
}
