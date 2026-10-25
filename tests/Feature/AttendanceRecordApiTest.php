<?php

namespace Tests\Feature;

use App\Models\AttendanceCorrectRequest;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceRecordApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = '/api/v1/attendance-records';

    /**
     * マイグレーション実行前にテスト専用DBであることを確認する。
     */
    protected function beforeRefreshingDatabase(): void
    {
        $connection = config('database.default');

        $this->assertSame(
            'testing',
            config('database.connections.'.$connection.'.database'),
            'APIテストはtestingデータベースで実行してください。'
        );
    }

    public function test_guest_can_read_paginated_summaries_without_private_user_fields(): void
    {
        $user = User::factory()->create(['name' => '山田太郎']);
        $old = $this->createAttendance($user, '2026-10-01');
        $latest = $this->createAttendance($user, '2026-10-02');

        $latest->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $response = $this->getJson(self::BASE_URL);

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $latest->id)
            ->assertJsonPath('data.1.id', $old->id)
            ->assertJsonPath('data.0.user_id', $user->id)
            ->assertJsonPath('data.0.user_name', '山田太郎')
            ->assertJsonPath('data.0.date', '2026-10-02')
            ->assertJsonPath('data.0.clock_in', '09:00:00')
            ->assertJsonPath('data.0.clock_out', '18:00:00')
            ->assertJsonPath('data.0.total_time', '08:00')
            ->assertJsonPath('data.0.total_break_time', '01:00')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['data', 'links', 'meta']);

        $this->assertArrayNotHasKey('user', $response->json('data.0'));
        $this->assertArrayNotHasKey('breaks', $response->json('data.0'));
        $this->assertArrayNotHasKey('applications', $response->json('data.0'));
        $response->assertDontSee($user->email);
    }

    public function test_list_filters_by_user_date_month_and_their_combination(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->createAttendance($user, '2026-05-01');
        $last = $this->createAttendance($user, '2026-05-31');
        $this->createAttendance($user, '2026-06-01');
        $this->createAttendance($other, '2026-05-15');

        $this->getJson(self::BASE_URL.'?user_id='.$user->id)
            ->assertOk()->assertJsonPath('meta.total', 3);

        $this->getJson(self::BASE_URL.'?date=2026-05-01')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id);

        $this->getJson(self::BASE_URL.'?month=2026-05')
            ->assertOk()->assertJsonPath('meta.total', 3);

        $this->getJson(self::BASE_URL.'?user_id='.$user->id.'&month=2026-05')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $last->id)
            ->assertJsonPath('data.1.id', $first->id);

        $this->getJson(self::BASE_URL.'?date=2026-05-01&month=2026-06')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_pagination_uses_requested_page_and_preserves_filters_in_links(): void
    {
        $user = User::factory()->create();
        $this->createAttendance($user, '2026-10-01');
        $second = $this->createAttendance($user, '2026-10-02');
        $this->createAttendance($user, '2026-10-03');

        $response = $this->getJson(
            self::BASE_URL.'?user_id='.$user->id.'&month=2026-10&per_page=1&page=2'
        );

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 3);

        parse_str(parse_url($response->json('links.next'), PHP_URL_QUERY), $nextQuery);
        $this->assertSame((string) $user->id, $nextQuery['user_id']);
        $this->assertSame('2026-10', $nextQuery['month']);
        $this->assertSame('1', $nextQuery['per_page']);
        $this->assertSame('3', $nextQuery['page']);
    }

    public function test_default_and_maximum_page_sizes_and_nullable_filters(): void
    {
        $user = User::factory()->create();
        collect(range(1, 21))->each(function (int $day) use ($user): void {
            $this->createAttendance($user, sprintf('2026-10-%02d', $day));
        });

        $this->getJson(self::BASE_URL.'?user_id=&date=&month=&page=&per_page=')
            ->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 21);

        $this->getJson(self::BASE_URL.'?per_page=100')
            ->assertOk()->assertJsonCount(21, 'data')
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_empty_list_returns_standard_pagination(): void
    {
        $this->getJson(self::BASE_URL)
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 0);
    }

    /** @dataProvider invalidFilterProvider */
    public function test_invalid_filters_return_422(string $query, string $field): void
    {
        $this->getJson(self::BASE_URL.'?'.$query)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilterProvider(): array
    {
        return [
            'user integer' => ['user_id=abc', 'user_id'],
            'user positive' => ['user_id=0', 'user_id'],
            'date format' => ['date=2026/10/01', 'date'],
            'date invalid day' => ['date=2026-02-30', 'date'],
            'month format' => ['month=2026-13', 'month'],
            'page positive' => ['page=0', 'page'],
            'page integer' => ['page=1.5', 'page'],
            'per page minimum' => ['per_page=0', 'per_page'],
            'per page maximum' => ['per_page=101', 'per_page'],
        ];
    }

    public function test_guest_can_read_detail_with_user_breaks_and_correction_applications(): void
    {
        $user = User::factory()->create();
        $attendance = $this->createAttendance($user);
        $break = $attendance->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);
        $application = $this->createCorrectionApplication($attendance);

        $response = $this->getJson(self::BASE_URL.'/'.$attendance->id);

        $response->assertOk()
            ->assertJsonPath('data.id', $attendance->id)
            ->assertJsonPath('data.user', ['id' => $user->id, 'name' => $user->name])
            ->assertJsonPath('data.breaks.0', [
                'id' => $break->id,
                'break_in' => '12:00:00',
                'break_out' => '13:00:00',
            ])
            ->assertJsonPath('data.applications.0.id', $application->id)
            ->assertJsonPath('data.applications.0.new_date', '2026-10-01')
            ->assertJsonPath('data.applications.0.new_clock_in', '09:30:00')
            ->assertJsonPath('data.applications.0.approval_status', '承認待ち')
            ->assertJsonPath('data.applications.0.application_date', '2026-10-02')
            ->assertJsonPath('data.total_time', '08:00');

        $response->assertDontSee($user->email)
            ->assertDontSee('password')
            ->assertDontSee('remember_token');
    }

    public function test_detail_has_empty_relation_arrays_and_unfinished_totals_are_null(): void
    {
        $attendance = $this->createAttendance(
            User::factory()->create(),
            '2026-10-01',
            ['clock_out' => null]
        );

        $this->getJson(self::BASE_URL.'/'.$attendance->id)
            ->assertOk()
            ->assertJsonPath('data.clock_out', null)
            ->assertJsonPath('data.total_time', null)
            ->assertJsonPath('data.total_break_time', '00:00')
            ->assertJsonPath('data.breaks', [])
            ->assertJsonPath('data.applications', []);
    }

    public function test_totals_sum_completed_break_seconds_before_discarding_seconds(): void
    {
        $attendance = $this->createAttendance(User::factory()->create());
        $attendance->breaks()->createMany([
            ['break_in' => '12:00:00', 'break_out' => '12:00:40'],
            ['break_in' => '13:00:00', 'break_out' => '13:00:40'],
            ['break_in' => '14:00:00', 'break_out' => null],
        ]);

        $this->getJson(self::BASE_URL.'/'.$attendance->id)
            ->assertOk()
            ->assertJsonPath('data.total_time', '08:58')
            ->assertJsonPath('data.total_break_time', '00:01');
    }

    public function test_listing_query_count_does_not_grow_with_the_number_of_records(): void
    {
        $user = User::factory()->create();
        collect(range(1, 6))->each(function (int $day) use ($user): void {
            $attendance = $this->createAttendance($user, sprintf('2026-10-%02d', $day));
            $attendance->breaks()->create([
                'break_in' => '12:00:00',
                'break_out' => '13:00:00',
            ]);
        });

        DB::enableQueryLog();

        try {
            DB::flushQueryLog();
            $this->getJson(self::BASE_URL.'?per_page=1')->assertOk();
            $smallCount = count(DB::getQueryLog());

            DB::flushQueryLog();
            $this->getJson(self::BASE_URL.'?per_page=6')
                ->assertOk()->assertJsonCount(6, 'data');
            $largeCount = count(DB::getQueryLog());

            $this->assertSame($smallCount, $largeCount);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_missing_detail_returns_the_specified_json_without_accept_header(): void
    {
        $this->get(self::BASE_URL.'/999999')
            ->assertNotFound()
            ->assertExactJson(['error' => '勤怠情報が見つかりませんでした。']);
    }

    public function test_authenticated_user_can_create_attendance_without_impersonating_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson(self::BASE_URL, $this->validPayload([
            'user_id' => $other->id,
            'admin_status' => true,
        ]))->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.date', '2026-10-01')
            ->assertJsonPath('data.clock_in', '09:00:00')
            ->assertJsonPath('data.clock_out', '18:00:00');

        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'date' => '2026-10-01',
            'comment' => 'API登録',
        ]);
        $this->assertDatabaseMissing('attendance_records', ['user_id' => $other->id]);
        $this->assertFalse($user->fresh()->admin_status);
    }

    public function test_create_allows_omitted_optional_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson(self::BASE_URL, [
            'date' => '2026-10-01',
            'clock_in' => '09:00:00',
        ])->assertCreated()
            ->assertJsonPath('data.clock_out', null)
            ->assertJsonPath('data.comment', null)
            ->assertJsonPath('data.total_time', null);
    }

    /** @dataProvider invalidPayloadProvider */
    public function test_create_validation_uses_specified_japanese_messages(
        array $changes,
        array $omit,
        string $field,
        string $message
    ): void {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $payload = array_diff_key($this->validPayload($changes), array_flip($omit));

        $this->postJson(self::BASE_URL, $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.'.$field.'.0', $message);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'required date' => [[], ['date'], 'date', '勤怠日は必須です。'],
            'date format' => [['date' => '2026/10/01'], [], 'date', '勤怠日は YYYY-MM-DD 形式で指定してください。'],
            'invalid date' => [['date' => '2026-02-30'], [], 'date', '勤怠日は YYYY-MM-DD 形式で指定してください。'],
            'required clock in' => [[], ['clock_in'], 'clock_in', '出勤時刻は必須です。'],
            'clock in format' => [['clock_in' => '09:00'], [], 'clock_in', '出勤時刻は HH:MM:SS 形式で指定してください。'],
            'invalid clock in' => [['clock_in' => '25:00:00'], [], 'clock_in', '出勤時刻は HH:MM:SS 形式で指定してください。'],
            'clock out format' => [['clock_out' => '18:00'], [], 'clock_out', '退勤時刻は HH:MM:SS 形式で指定してください。'],
            'clock out before' => [['clock_out' => '08:59:59'], [], 'clock_out', '退勤時刻は出勤時刻より後の時刻を指定してください。'],
            'clock out equal' => [['clock_out' => '09:00:00'], [], 'clock_out', '退勤時刻は出勤時刻より後の時刻を指定してください。'],
            'comment length' => [['comment' => str_repeat('あ', 256)], [], 'comment', '備考は 255 文字以内で入力してください。'],
            'comment string' => [['comment' => ['備考']], [], 'comment', '備考は文字列で入力してください。'],
        ];
    }

    public function test_create_rejects_duplicate_date_for_the_same_user_only(): void
    {
        $user = User::factory()->create();
        $this->createAttendance($user);
        $this->createAttendance(User::factory()->create(), '2026-10-02');
        Sanctum::actingAs($user, ['*']);

        $this->postJson(self::BASE_URL, $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'この日付の勤怠は既に登録されています。');

        $this->postJson(self::BASE_URL, $this->validPayload(['date' => '2026-10-02']))
            ->assertCreated();
    }

    /** @dataProvider writeMethodProvider */
    public function test_unauthenticated_writes_return_401_without_accept_header(string $method): void
    {
        $url = self::BASE_URL.($method === 'POST' ? '' : '/999999');

        $this->call($method, $url, $this->validPayload())
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public static function writeMethodProvider(): array
    {
        return [['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    public function test_real_bearer_token_authenticates_writes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api-test')->plainTextToken;

        $this->withToken($token)
            ->postJson(self::BASE_URL, $this->validPayload())
            ->assertCreated()->assertJsonPath('data.user_id', $user->id);
    }

    public function test_invalid_bearer_token_is_rejected(): void
    {
        $this->withToken('invalid-token')
            ->postJson(self::BASE_URL, $this->validPayload())
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_validation_returns_json_even_without_accept_header(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->post(self::BASE_URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'clock_in'])
            ->assertJsonPath('errors.date.0', '勤怠日は必須です。');
    }

    /** @dataProvider updateActorProvider */
    public function test_owner_or_admin_can_update_using_put_or_patch(string $method, bool $asAdmin): void
    {
        $owner = User::factory()->create();
        $actor = $asAdmin ? User::factory()->create(['admin_status' => true]) : $owner;
        $attendance = $this->createAttendance($owner);
        Sanctum::actingAs($actor, ['*']);

        $this->json($method, self::BASE_URL.'/'.$attendance->id, $this->validPayload([
            'user_id' => $actor->id,
            'clock_out' => '19:00:00',
            'comment' => '更新',
        ]))->assertOk()
            ->assertJsonPath('data.id', $attendance->id)
            ->assertJsonPath('data.user_id', $owner->id)
            ->assertJsonPath('data.clock_out', '19:00:00');

        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendance->id,
            'user_id' => $owner->id,
            'clock_out' => '19:00:00',
            'comment' => '更新',
        ]);
    }

    public static function updateActorProvider(): array
    {
        return [
            ['PUT', false],
            ['PATCH', false],
            ['PUT', true],
            ['PATCH', true],
        ];
    }

    /** @dataProvider partialUpdateProvider */
    public function test_partial_update_preserves_omitted_fields(array $changes): void
    {
        $user = User::factory()->create();
        $attendance = $this->createAttendance($user, '2026-10-01', ['comment' => '元の備考']);
        Sanctum::actingAs($user, ['*']);

        $expected = array_replace([
            'date' => '2026-10-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '元の備考',
        ], $changes);

        $response = $this->patchJson(self::BASE_URL.'/'.$attendance->id, $changes)->assertOk();
        collect($expected)->each(function (mixed $value, string $key) use ($response): void {
            $response->assertJsonPath('data.'.$key, $value);
        });
        $this->assertDatabaseHas('attendance_records', ['id' => $attendance->id] + $expected);
    }

    public static function partialUpdateProvider(): array
    {
        return [
            'comment only' => [['comment' => '備考だけ変更']],
            'date only' => [['date' => '2026-10-02']],
            'clock in only' => [['clock_in' => '10:00:00']],
            'clock out only' => [['clock_out' => '19:00:00']],
            'clear clock out' => [['clock_out' => null]],
            'clear comment' => [['comment' => null]],
            'both times' => [['clock_in' => '19:00:00', 'clock_out' => '20:00:00']],
            'clear end and change start' => [['clock_in' => '19:00:00', 'clock_out' => null]],
            'empty update' => [[]],
        ];
    }

    /** @dataProvider invalidUpdateProvider */
    public function test_update_validates_sent_fields_and_merged_times(
        array $changes,
        string $field,
        string $message
    ): void {
        $user = User::factory()->create();
        $attendance = $this->createAttendance($user);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson(self::BASE_URL.'/'.$attendance->id, $changes)
            ->assertUnprocessable()->assertJsonPath('errors.'.$field.'.0', $message);

        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendance->id,
            'date' => '2026-10-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
    }

    public static function invalidUpdateProvider(): array
    {
        return [
            'null date' => [['date' => null], 'date', '勤怠日は必須です。'],
            'date format' => [['date' => '2026/10/01'], 'date', '勤怠日は YYYY-MM-DD 形式で指定してください。'],
            'null clock in' => [['clock_in' => null], 'clock_in', '出勤時刻は必須です。'],
            'clock in format' => [['clock_in' => '09:00'], 'clock_in', '出勤時刻は HH:MM:SS 形式で指定してください。'],
            'clock out format' => [['clock_out' => '18:00'], 'clock_out', '退勤時刻は HH:MM:SS 形式で指定してください。'],
            'new start after existing end' => [['clock_in' => '19:00:00'], 'clock_out', '退勤時刻は出勤時刻より後の時刻を指定してください。'],
            'new end before existing start' => [['clock_out' => '08:00:00'], 'clock_out', '退勤時刻は出勤時刻より後の時刻を指定してください。'],
            'equal merged times' => [['clock_in' => '18:00:00'], 'clock_out', '退勤時刻は出勤時刻より後の時刻を指定してください。'],
            'comment length' => [['comment' => str_repeat('あ', 256)], 'comment', '備考は 255 文字以内で入力してください。'],
        ];
    }

    public function test_admin_date_uniqueness_uses_the_record_owner_and_ignores_the_record_itself(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['admin_status' => true]);
        $attendance = $this->createAttendance($owner);
        $this->createAttendance($owner, '2026-10-02');
        $this->createAttendance($admin, '2026-10-03');
        Sanctum::actingAs($admin, ['*']);
        $url = self::BASE_URL.'/'.$attendance->id;

        $this->patchJson($url, ['date' => '2026-10-01'])->assertOk();
        $this->patchJson($url, ['date' => '2026-10-02', 'user_id' => $admin->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'この日付の勤怠は既に登録されています。');
        $this->patchJson($url, ['date' => '2026-10-03'])->assertOk();

        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendance->id,
            'user_id' => $owner->id,
            'date' => '2026-10-03',
        ]);
    }

    /** @dataProvider forbiddenMethodProvider */
    public function test_other_users_cannot_update_or_delete_even_with_invalid_input(string $method): void
    {
        $owner = User::factory()->create();
        $attendance = $this->createAttendance($owner);
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->json($method, self::BASE_URL.'/'.$attendance->id, ['date' => 'invalid'])
            ->assertForbidden()
            ->assertExactJson(['error' => 'この操作を実行する権限がありません。']);

        $this->assertDatabaseHas('attendance_records', ['id' => $attendance->id]);
    }

    public static function forbiddenMethodProvider(): array
    {
        return [['PUT'], ['PATCH'], ['DELETE']];
    }

    /** @dataProvider forbiddenMethodProvider */
    public function test_authenticated_writes_to_missing_records_return_404(string $method): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->json($method, self::BASE_URL.'/999999', $this->validPayload())
            ->assertNotFound()
            ->assertExactJson(['error' => '勤怠情報が見つかりませんでした。']);
    }

    /** @dataProvider deleteActorProvider */
    public function test_owner_or_admin_can_delete_with_cascading_relations(bool $asAdmin): void
    {
        $owner = User::factory()->create();
        $actor = $asAdmin ? User::factory()->create(['admin_status' => true]) : $owner;
        $attendance = $this->createAttendance($owner);
        $otherAttendance = $this->createAttendance($owner, '2026-10-02');
        $break = $attendance->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);
        $application = $this->createCorrectionApplication($attendance);
        $proposal = $application->proposalBreaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->deleteJson(self::BASE_URL.'/'.$attendance->id)->assertNoContent();
        $this->assertSame('', $response->getContent());
        $this->assertDatabaseMissing('attendance_records', ['id' => $attendance->id]);
        $this->assertDatabaseMissing('breaks', ['id' => $break->id]);
        $this->assertDatabaseMissing('attendance_correct_requests', ['id' => $application->id]);
        $this->assertDatabaseMissing('proposal_breaks', ['id' => $proposal->id]);
        $this->assertDatabaseHas('attendance_records', ['id' => $otherAttendance->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public static function deleteActorProvider(): array
    {
        return [[false], [true]];
    }

    public function test_web_guest_still_redirects_to_login(): void
    {
        $this->get('/attendance')->assertRedirect('/login');
    }

    /**
     * APIで受け付ける正常な登録入力を返す。
     *
     * @param  array<string, mixed>  $overrides  置き換える入力
     * @return array<string, mixed> 登録入力
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'date' => '2026-10-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => 'API登録',
        ], $overrides);
    }

    /**
     * 指定ユーザーの勤怠を作成する。
     *
     * @param  User  $user  勤怠の所有者
     * @param  string  $date  勤怠日
     * @param  array<string, mixed>  $overrides  置き換える値
     * @return AttendanceRecord 作成した勤怠
     */
    private function createAttendance(
        User $user,
        string $date = '2026-10-01',
        array $overrides = []
    ): AttendanceRecord {
        return $user->attendanceRecords()->create(array_replace([
            'date' => $date,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ], $overrides));
    }

    /**
     * 勤怠に紐づく承認待ちの修正申請を作成する。
     *
     * @param  AttendanceRecord  $attendanceRecord  対象勤怠
     * @return AttendanceCorrectRequest 作成した申請
     */
    private function createCorrectionApplication(AttendanceRecord $attendanceRecord): AttendanceCorrectRequest
    {
        return $attendanceRecord->applications()->create([
            'user_id' => $attendanceRecord->user_id,
            'new_date' => $attendanceRecord->date->format('Y-m-d'),
            'new_clock_in' => '09:30:00',
            'new_clock_out' => '18:30:00',
            'comment' => '打刻修正',
            'approval_status' => AttendanceCorrectRequest::STATUS_PENDING,
            'application_date' => '2026-10-02',
        ]);
    }
}
