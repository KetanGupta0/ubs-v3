<?php

use App\Models\Document;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ApiContract;

/*
 * The API document (docs/api/openapi.yaml) is the contract the apps are built
 * against (Step I, X25). Every route is in it, everything in it is a route,
 * and every answer below is checked against the schema it documents, on the
 * demo data every screen is shown with.
 */

beforeEach(function () {
    // Answers as production gives them: an error says what, never where in the code.
    config(['app.debug' => false]);

    $this->seed(DatabaseSeeder::class);
    Storage::fake('private');

    $this->client = User::query()->where('email', 'client@unboundbyte.test')->sole();
    $this->student = User::query()->where('email', 'student@unboundbyte.test')->sole();

    $this->tokenFor = fn (User $user) => $user->createToken('Contract test', ['*'])->plainTextToken;

    /** Call the API and hold the answer to the document. */
    $this->api = function (string $method, string $path, ?string $token = null, array $data = [], array $values = [], array $headers = []) {
        // Each call stands alone, as an app's do: no user, and no token, kept from the last one.
        app('auth')->forgetGuards();
        $this->flushHeaders();

        $uri = '/api/v1'.preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) $values[$m[1]], $path);
        $request = $token ? $this->withToken($token) : $this;
        $response = $request->withHeaders($headers)->json($method, $uri, $data);

        ApiContract::assertMatches($response, $method, $path);

        return $response;
    };
});

it('documents every route, and routes everything it documents', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/v1/') || $route->uri() === 'api/v1')
        ->flatMap(fn (RoutingRoute $route) => collect($route->methods())
            ->reject(fn (string $method) => in_array($method, ['HEAD', 'OPTIONS'], true))
            ->map(fn (string $method) => $method.' '.substr('/'.$route->uri(), strlen('/api/v1'))))
        ->sort()
        ->values()
        ->all();

    expect($routes)->toBe(ApiContract::operations());
});

it('signs in, and answers about the account and its devices, as documented', function () {
    $device = ['device_name' => 'Pixel 9', 'device_identifier' => 'pixel-abc', 'platform' => 'android', 'app_version' => '1.0.0'];

    ($this->api)('POST', '/auth/login', data: ['identifier' => 'client@unboundbyte.test', 'password' => 'wrong', ...$device])->assertUnprocessable();
    $token = ($this->api)('POST', '/auth/login', data: ['identifier' => 'client@unboundbyte.test', 'password' => DemoAccountSeeder::PASSWORD, ...$device])
        ->assertOk()->json('token');

    ($this->api)('POST', '/auth/code', data: ['identifier' => 'nobody@example.com'])->assertOk();
    ($this->api)('POST', '/auth/code/verify', data: ['identifier' => 'nobody@example.com', 'code' => '123456', ...$device])->assertUnprocessable();
    ($this->api)('POST', '/auth/two-factor', data: ['code' => '123456', ...$device])->assertUnauthorized();
    ($this->api)('POST', '/auth/two-factor', $token, ['code' => '123456', ...$device])->assertForbidden();

    ($this->api)('GET', '/me')->assertUnauthorized();
    $me = ($this->api)('GET', '/me', $token)->assertOk()->assertHeader('ETag');
    ($this->api)('GET', '/me', $token, headers: ['If-None-Match' => $me->headers->get('ETag')])->assertStatus(304);

    ($this->api)('POST', '/devices/push-token', $token, ['push_token' => 'fcm-token', 'platform' => 'android'])->assertOk();
    ($this->api)('GET', '/devices', $token)->assertOk()->assertJsonPath('data.0.current', true);
    ($this->api)('DELETE', '/devices/{device}', $token, values: ['device' => 999999])->assertNotFound();

    ($this->api)('POST', '/auth/password', $token, ['current_password' => 'wrong', 'password' => 'Another-pass-123', 'password_confirmation' => 'Another-pass-123'])->assertUnprocessable();
    ($this->api)('POST', '/auth/password', $token, ['current_password' => DemoAccountSeeder::PASSWORD, 'password' => 'Another-pass-123', 'password_confirmation' => 'Another-pass-123'])->assertOk();

    $fresh = ($this->api)('POST', '/auth/refresh', $token)->assertOk()->json('token');
    ($this->api)('GET', '/me', $token)->assertUnauthorized();
    ($this->api)('POST', '/auth/logout', $fresh)->assertOk();

    $other = ($this->tokenFor)($this->client);
    ($this->api)('POST', '/auth/logout-all', $other)->assertOk();
});

it('answers 423 to everything but signing out and choosing a password while a temporary password stands', function () {
    $this->student->forceFill(['must_change_password' => true])->save();
    $token = ($this->tokenFor)($this->student);

    ($this->api)('GET', '/student/overview', $token)->assertStatus(423)->assertJsonPath('code', 'password_change_required');
    ($this->api)('GET', '/devices', $token)->assertStatus(423);
    ($this->api)('GET', '/me', $token)->assertOk();
});

it('serves the client app as documented', function () {
    $token = ($this->tokenFor)($this->client);
    $project = Project::query()->forClient($this->client->clientAccount())->firstOrFail();

    Storage::disk('private')->put('documents/brief.pdf', '%PDF-1.4 brief');
    $document = Document::query()->create([
        'client_id' => $this->client->clientAccount()->id, 'name' => 'Brief.pdf', 'path' => 'documents/brief.pdf',
        'mime' => 'application/pdf', 'size' => 14, 'category' => 'contract',
    ]);

    ($this->api)('GET', '/client/overview', $token)->assertOk();
    ($this->api)('GET', '/client/projects', $token)->assertOk()->assertJsonPath('data.0.id', $project->id);
    ($this->api)('GET', '/client/projects/{project}', $token, values: ['project' => $project->id])->assertOk();
    ($this->api)('GET', '/client/projects/{project}', $token, values: ['project' => 999999])->assertNotFound();

    $url = ($this->api)('GET', '/client/documents', $token)->assertOk()->json('data.0.downloadUrl');
    expect($url)->toEndWith("/api/v1/client/documents/{$document->id}/download");
    ($this->api)('GET', '/client/documents/{document}/download', $token, values: ['document' => $document->id])->assertOk();

    ($this->api)('GET', '/client/tickets', $token)->assertOk();
    ($this->api)('POST', '/client/tickets', $token, ['subject' => 'Login page is slow', 'body' => 'Since this morning.', 'priority' => 'high'])->assertCreated();
    ($this->api)('POST', '/client/tickets', $token, ['subject' => '', 'body' => '', 'priority' => 'whenever'])->assertUnprocessable();

    ($this->api)('GET', '/client/payments', $token)->assertOk();
    $receipt = ($this->api)('GET', '/client/transactions', $token)->assertOk()->collect('data')->firstWhere('receiptUrl', '!==', null);
    $invoice = ($this->api)('GET', '/client/invoices', $token)->assertOk()->json('data.0');

    ($this->api)('GET', '/client/invoices/{invoice}/pdf', $token, values: ['invoice' => $invoice['id']])->assertOk();
    ($this->api)('GET', '/client/receipts/{reference}', $token, values: ['reference' => $receipt['reference']])->assertOk();

    // A student's token is a valid token, and valid is not entitled.
    ($this->api)('GET', '/client/overview', ($this->tokenFor)($this->student))->assertForbidden();
});

it('serves the student app as documented', function () {
    $token = ($this->tokenFor)($this->student);
    $course = ($this->api)('GET', '/student/courses', $token)->assertOk()->collect('data')->firstWhere('status', 'active');

    ($this->api)('GET', '/student/overview', $token)->assertOk();
    $modules = ($this->api)('GET', '/student/courses/{course}', $token, values: ['course' => $course['id']])->assertOk()->json('data.modules');

    $lessons = collect($modules)->flatMap(fn ($module) => $module['lessons']);
    $open = $lessons->firstWhere('lock.open', true);
    $sealed = $lessons->firstWhere('lock.open', false);

    $lesson = Lesson::query()->findOrFail($open['id']);
    Storage::disk('private')->put('materials/handout.pdf', '%PDF-1.4 handout');
    $material = Material::query()->create([
        'course_id' => $course['id'], 'lesson_id' => $lesson->id, 'title' => 'Handout', 'path' => 'materials/handout.pdf',
        'mime' => 'application/pdf', 'size' => 16, 'is_downloadable' => true,
    ]);

    $download = ($this->api)('GET', '/student/courses/{course}/lessons/{lesson}', $token, values: ['course' => $course['id'], 'lesson' => $lesson->id])
        ->assertOk()->collect('data.materials')->firstWhere('id', $material->id)['downloadUrl'];
    expect($download)->toEndWith("/api/v1/student/courses/{$course['id']}/materials/{$material->id}");
    ($this->api)('GET', '/student/courses/{course}/materials/{material}', $token, values: ['course' => $course['id'], 'material' => $material->id])->assertOk();

    if ($sealed) {
        ($this->api)('GET', '/student/courses/{course}/lessons/{lesson}', $token, values: ['course' => $course['id'], 'lesson' => $sealed['id']])
            ->assertStatus(423)->assertJsonMissingPath('data.content');
        ($this->api)('POST', '/student/courses/{course}/lessons/{lesson}/complete', $token, values: ['course' => $course['id'], 'lesson' => $sealed['id']])->assertForbidden();
    }

    ($this->api)('POST', '/student/courses/{course}/lessons/{lesson}/complete', $token, values: ['course' => $course['id'], 'lesson' => $lesson->id])->assertOk();
    ($this->api)('GET', '/student/courses/{course}', $token, values: ['course' => 999999])->assertNotFound();

    foreach (['/student/classes', '/student/assignments', '/student/results', '/student/announcements', '/student/certificates', '/student/payments'] as $path) {
        ($this->api)('GET', $path, $token)->assertOk();
    }
});

it('serves chat as documented, pictures included', function () {
    $token = ($this->tokenFor)($this->student);
    $room = ($this->api)('GET', '/chat', $token)->assertOk()->json('data.0.id');

    ($this->api)('GET', '/chat/{conversation}/messages', $token, values: ['conversation' => $room])->assertOk();
    ($this->api)('GET', '/chat/{conversation}/messages', $token, values: ['conversation' => 999999])->assertNotFound();
    ($this->api)('POST', '/chat/{conversation}/messages', $token, ['kind' => 'text', 'body' => 'Is the class at six?'], ['conversation' => $room])->assertCreated();
    ($this->api)('POST', '/chat/{conversation}/messages', $token, ['kind' => 'video'], ['conversation' => $room])->assertUnprocessable();

    $picture = $this->withToken($token)->post("/api/v1/chat/{$room}/messages", ['kind' => 'image', 'file' => UploadedFile::fake()->image('board.jpg', 640, 480)], ['Accept' => 'application/json']);
    ApiContract::assertMatches($picture->assertCreated(), 'POST', '/chat/{conversation}/messages');

    $mediaUrl = $picture->json('data.mediaUrl');
    expect($mediaUrl)->toContain('/api/v1/chat/media/');
    $this->withToken($token)->get($mediaUrl)->assertOk();
    $message = Message::query()->findOrFail($picture->json('data.id'));
    ($this->api)('GET', '/chat/media/{message}', $token, values: ['message' => $message->id])->assertForbidden();

    ($this->api)('POST', '/chat/{conversation}/read', $token, values: ['conversation' => $room])->assertOk();
});

it('answers a licence check as documented', function () {
    ($this->api)('POST', '/licences/check', data: ['key' => 'UBS-NOPE0-NOPE0-NOPE0-NOPE0', 'site' => 'clinic.example.com'])
        ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('status', 'invalid');
    ($this->api)('POST', '/licences/check', data: ['key' => 'UBS-NOPE0', 'site' => 'not an address'])->assertUnprocessable();
});
