<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\User;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EncodingWorkbook;
use Tests\TestCase;

class ImportUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_is_checked_first_then_imported_on_confirmation(): void
    {
        Storage::fake();
        $this->seed(StrandSeeder::class);
        $year = SchoolYear::factory()->active()->create();
        $registrar = User::factory()->role(Role::Registrar)->create();

        $file = new UploadedFile(EncodingWorkbook::make()->learner()->save(), 'ENROLLMENT_ENCODING.xlsx', test: true);

        $this->actingAs($registrar)
            ->post('/imports/preview', ['file' => $file, 'school_year_id' => $year->id])
            ->assertRedirect('/imports');

        $this->assertSame(0, Enrollment::count(), 'The check step must not save anything.');
        $token = session('import')['token'];

        $this->actingAs($registrar)->post('/imports', ['token' => $token])->assertRedirect('/imports');

        $this->assertSame(1, Enrollment::count());
        $this->assertSame($registrar->id, Enrollment::first()->encoded_by);
        Storage::assertDirectoryEmpty('imports');
    }

    public function test_a_wrong_token_imports_nothing(): void
    {
        $registrar = User::factory()->role(Role::Registrar)->create();

        $this->actingAs($registrar)->post('/imports', ['token' => 'nope'])->assertSessionHas('error');
        $this->assertSame(0, Enrollment::count());
    }

    public function test_a_file_that_is_not_a_real_workbook_gets_a_message_not_a_crash(): void
    {
        Storage::fake();
        $year = SchoolYear::factory()->active()->create();
        $registrar = User::factory()->role(Role::Registrar)->create();
        $fake = UploadedFile::fake()->createWithContent('ENROLLMENT.xlsx', 'this is not a spreadsheet');

        $this->actingAs($registrar)
            ->post('/imports/preview', ['file' => $fake, 'school_year_id' => $year->id])
            ->assertSessionHasErrors('file');
    }

    public function test_only_one_import_runs_at_a_time(): void
    {
        Storage::fake();
        $this->seed(StrandSeeder::class);
        $year = SchoolYear::factory()->active()->create();
        $registrar = User::factory()->role(Role::Registrar)->create();
        $file = new UploadedFile(EncodingWorkbook::make()->learner()->save(), 'ENROLLMENT_ENCODING.xlsx', test: true);
        $this->actingAs($registrar)->post('/imports/preview', ['file' => $file, 'school_year_id' => $year->id]);

        $lock = Cache::lock('enrollment-import', 60);
        $lock->get();

        $this->actingAs($registrar)->post('/imports', ['token' => session('import')['token']])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Another import is running'));
        $this->assertSame(0, Enrollment::count());

        $lock->release();
    }
}
