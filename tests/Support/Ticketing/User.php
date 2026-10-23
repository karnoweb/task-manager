<?php

namespace Karnoweb\TaskManager\Tests\Support\Ticketing;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

final class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';
}
