<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\User;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
}
