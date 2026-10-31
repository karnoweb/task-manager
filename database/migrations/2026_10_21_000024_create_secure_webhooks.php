<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;
use Karnoweb\TaskManager\Schema\Tables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('webhook_endpoints'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('endpoint_key')->unique();
            $t->string('scope_key', 120);
            $t->string('owner_ref', 120);
            $t->string('url', 2048);
            $t->string('secret_ref', 120);
            $t->text('event_types');
            $t->unsignedInteger('version')->default(1);
            $t->boolean('active')->default(true);
            $t->index(['scope_key', 'owner_ref', 'active', 'id'], Tables::name('webhook_owner'));
        });
        Schema::create(Tables::name('webhook_deliveries'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('endpoint_id')->constrained(Tables::name('webhook_endpoints'))->restrictOnDelete();
            $t->uuid('event_id');
            $t->foreign('event_id')->references('event_id')->on(Tables::name('outbox'))->restrictOnDelete();
            $t->unsignedInteger('endpoint_version');
            $t->string('state', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('next_attempt_at', 6)->nullable();
            $t->timestamp('lease_until', 6)->nullable();
            $t->uuid('lease_token')->nullable();
            $t->unsignedSmallInteger('response_status')->nullable();
            $t->string('reason_code', 80)->nullable();
            $t->unique(['endpoint_id', 'event_id'], Tables::name('webhook_dedupe'));
            $t->index(['state', 'next_attempt_at', 'id'], Tables::name('webhook_retry'));
        });
        Schema::create(Tables::name('webhook_receipts'), function (Blueprint $t) {
            $t->foreignId('endpoint_id')->constrained(Tables::name('webhook_endpoints'))->restrictOnDelete();
            $t->uuid('event_id');
            $t->timestamp('received_at', 6);
            $t->primary(['endpoint_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('webhook_receipts'));
        Schema::dropIfExists(Tables::name('webhook_deliveries'));
        Schema::dropIfExists(Tables::name('webhook_endpoints'));
    }
};
