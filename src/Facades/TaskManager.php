<?php

namespace Karnoweb\TaskManager\Facades;

use Illuminate\Support\Facades\Facade;
use Karnoweb\TaskManager\TaskService;

final class TaskManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Karnoweb\TaskManager\TaskManager::class;
    }
}
