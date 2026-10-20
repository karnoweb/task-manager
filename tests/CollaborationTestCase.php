<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Collaboration\AttachmentService;
use Karnoweb\TaskManager\Collaboration\ChecklistService;
use Karnoweb\TaskManager\Collaboration\CollaborationService;
use Karnoweb\TaskManager\Contracts\AttachmentStorage;
use Karnoweb\TaskManager\TaskResourceService;
use Karnoweb\TaskManager\Tests\Support\LocalAttachmentStorage;

abstract class CollaborationTestCase extends PhaseFourTestCase
{
    protected LocalAttachmentStorage $storage;
    protected function setUp(): void { parent::setUp(); $this->storage = new LocalAttachmentStorage(); $this->app->instance(AttachmentStorage::class, $this->storage); }
    protected function collaboration(): CollaborationService { return $this->app->make(CollaborationService::class); }
    protected function checklists(): ChecklistService { return $this->app->make(ChecklistService::class); }
    protected function attachments(): AttachmentService { return $this->app->make(AttachmentService::class); }
    protected function resources(): TaskResourceService { return $this->app->make(TaskResourceService::class); }
    protected function itemIn(array $list, string $title): array
    {
        $list = $this->checklists()->get($this->manager(), $list['public_key']); return $this->checklists()->addItem($this->manager(), $list['public_key'], $list['version'], $title, null, null, 'item-'.$title);
    }
}
