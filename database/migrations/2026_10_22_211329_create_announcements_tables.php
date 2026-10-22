<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('announcements')) {
            Schema::create('announcements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sent_by_user_id')->constrained('users')->restrictOnDelete();
                $table->string('type');
                $table->text('message');
                $table->json('audience_filter')->nullable();
                $table->string('channel');
                $table->timestamp('scheduled_for')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->unsignedInteger('recipients_count')->default(0);
                $table->string('status')->default('draft');
                $table->timestamps();
                $table->index(['hospital_id', 'status', 'scheduled_for']);
                $table->index(['hospital_id', 'type', 'created_at']);
            });
        }

        if (! Schema::hasTable('announcement_recipients')) {
            Schema::create('announcement_recipients', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
                $table->foreignId('appointment_request_id')->nullable()->constrained()->nullOnDelete();
                $table->string('recipient_name');
                $table->string('recipient_phone');
                $table->text('rendered_message');
                $table->timestamp('sent_at')->nullable();
                $table->string('delivery_status')->default('queued');
                $table->string('provider_message_id')->nullable();
                $table->timestamps();
                $table->unique(['announcement_id', 'appointment_request_id']);
                $table->index(['announcement_id', 'delivery_status']);
            });
        }

        $this->assertRequiredColumns('announcements', [
            'id',
            'hospital_id',
            'sent_by_user_id',
            'type',
            'message',
            'audience_filter',
            'channel',
            'scheduled_for',
            'sent_at',
            'recipients_count',
            'status',
            'created_at',
            'updated_at',
        ]);

        $this->assertRequiredColumns('announcement_recipients', [
            'id',
            'announcement_id',
            'appointment_request_id',
            'recipient_name',
            'recipient_phone',
            'rendered_message',
            'sent_at',
            'delivery_status',
            'provider_message_id',
            'created_at',
            'updated_at',
        ]);

        $this->ensureIndex('announcements', ['hospital_id', 'status', 'scheduled_for']);
        $this->ensureIndex('announcements', ['hospital_id', 'type', 'created_at']);
        $this->ensureRecipientUniqueIndex();
        $this->ensureIndex('announcement_recipients', ['announcement_id', 'delivery_status']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
        Schema::dropIfExists('announcements');
    }

    /**
     * @param  array<int, string>  $requiredColumns
     */
    private function assertRequiredColumns(string $table, array $requiredColumns): void
    {
        $missingColumns = array_values(array_diff($requiredColumns, Schema::getColumnListing($table)));

        if ($missingColumns !== []) {
            throw new RuntimeException(sprintf(
                'Existing %s table is missing required columns: %s.',
                $table,
                implode(', ', $missingColumns),
            ));
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function ensureIndex(string $table, array $columns): void
    {
        if (Schema::hasIndex($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->index($columns);
        });
    }

    private function ensureRecipientUniqueIndex(): void
    {
        $columns = ['announcement_id', 'appointment_request_id'];

        if (Schema::hasIndex('announcement_recipients', $columns, 'unique')) {
            return;
        }

        $hasDuplicateRecipients = DB::table('announcement_recipients')
            ->whereNotNull('appointment_request_id')
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateRecipients) {
            throw new RuntimeException(
                'Cannot add the announcement recipient unique index while duplicate appointment recipients exist.',
            );
        }

        Schema::table('announcement_recipients', function (Blueprint $table) use ($columns): void {
            $table->unique($columns);
        });
    }
};
