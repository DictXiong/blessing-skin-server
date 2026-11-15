<?php

// This file was generated entirely by AI.
// It has not been reviewed line by line by a human.

namespace Tests\ServicesTest;

use App\Models\Player;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SearchCompatibilityTest extends TestCase
{
    use DatabaseTransactions;

    public function testAdminUserSearchMatchesNicknameAndEmailThroughHttp(): void
    {
        $this->actingAs(User::factory()->admin()->create(['nickname' => 'Operator', 'email' => 'operator@example.test']));
        $alice = User::factory()->create(['nickname' => 'Alice Smith', 'email' => 'alice@search.test']);
        User::factory()->create(['nickname' => 'Bob', 'email' => 'bob@example.test']);

        foreach (['Alice', 'search.test', '"Alice Smith"', 'Alice score:1000'] as $query) {
            $response = $this->getJson(route('admin.users.list', ['q' => $query]))->assertOk();
            $this->assertSame([$alice->uid], array_column($response->json('data'), 'uid'), $query);
        }
    }

    public function testFreeTextTreatsSqlWildcardsAsLiteralCharacters(): void
    {
        foreach (['100% real', 'a_b', 'c\\d', 'wow!yes', '中文名字', 'say "hello"'] as $nickname) {
            User::factory()->create(['nickname' => $nickname]);
        }
        User::factory()->create(['nickname' => 'plain']);
        foreach (['100%', 'a_b', 'c\\d', '"wow!yes"', '中文名字', '"say \\"hello\\""'] as $query) {
            $this->assertSame(1, User::usingSearchString($query)->count(), $query);
        }
        $this->assertSame(0, User::usingSearchString('"%\' OR 1=1 --"')->count());
    }

    public function testBooleanLogicComparisonsAndLists(): void
    {
        $alice = User::factory()->create(['nickname' => 'Alice', 'score' => 10, 'verified' => true]);
        $bob = User::factory()->create(['nickname' => 'Bob', 'score' => 90, 'verified' => false]);
        User::factory()->create(['nickname' => 'Carol', 'score' => 100, 'verified' => true]);

        $this->assertSame([$bob->uid], User::usingSearchString('(Alice or Bob) and score>50')->pluck('uid')->all());
        $this->assertSame(2, User::usingSearchString('not (Alice or Bob) or score:10')->count());
        $this->assertSame(2, User::usingSearchString('score in (10,90)')->count());
        $this->assertSame(1, User::usingSearchString('score not in (10,90)')->count());
        $this->assertSame(2, User::usingSearchString('score:10,90')->count());
        $this->assertSame(2, User::usingSearchString('verified')->count());
        $this->assertSame([$bob->uid], User::usingSearchString('not verified')->pluck('uid')->all());
        $this->assertSame([$alice->uid], User::usingSearchString('verified:true score<=10')->pluck('uid')->all());
        $this->assertSame(2, User::usingSearchString('score!=10')->count());
        $this->assertSame(3, User::usingSearchString(null)->count());
        $this->assertSame(3, User::usingSearchString('  ')->count());
    }

    public function testDatesCoverTheRequestedPrecision(): void
    {
        User::factory()->create(['register_at' => '2020-01-01 13:45:00']);
        User::factory()->create(['register_at' => '2020-01-02 00:00:00']);
        User::factory()->create(['register_at' => '2021-01-01 00:00:00']);

        foreach (['register_at:2020' => 2, 'register_at:2020-01' => 2, 'register_at:2020-01-01' => 1,
            'register_at>2020-01-01' => 2, 'register_at<=2020-01-01' => 1,
            'register_at!=2020-01-01' => 2, 'register_at:"2020-01-01 13:45"' => 1] as $query => $count) {
            $this->assertSame($count, User::usingSearchString($query)->count(), $query);
        }
        $this->assertSame(0, User::usingSearchString('last_sign_at:NULL')->count());
        $this->assertSame(3, User::usingSearchString('last_sign_at!=NULL')->count());
    }

    public function testPlayerAliasesStillWork(): void
    {
        $player = Player::factory()->create(['tid_skin' => 5, 'tid_cape' => 7]);
        Player::factory()->create(['tid_skin' => 6, 'tid_cape' => 8]);
        foreach (['skin:5', 'tid_skin:5', 'cape:7', 'tid_cape:7'] as $query) {
            $this->assertSame([$player->pid], Player::usingSearchString($query)->pluck('pid')->all(), $query);
        }
    }

    public function testReportPageDefaultQueryFiltersAndSortsThroughHttp(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $reports = [];
        foreach ([[0, '2020-01-01'], [1, '2020-01-03'], [0, '2020-01-02']] as [$status, $date]) {
            $report = new Report(['uploader' => $admin->uid, 'reporter' => $admin->uid, 'reason' => 'synthetic', 'status' => $status]);
            $report->tid = 1;
            $report->report_at = $date.' 00:00:00';
            $report->save();
            $reports[] = $report;
        }
        $response = $this->getJson('/admin/reports/list?'.http_build_query(['q' => 'status:0 sort:-report_at']))->assertOk();
        $this->assertSame([$reports[2]->id, $reports[0]->id], array_column($response->json('data'), 'id'));
    }

    public function testSortProjectionAndPagingKeywordsArePreserved(): void
    {
        $first = User::factory()->create(['score' => 10]);
        $second = User::factory()->create(['score' => 20]);
        User::factory()->create(['score' => 20]);
        $this->assertSame([$second->uid], User::usingSearchString('sort:-score,-uid from:1 limit:1')->pluck('uid')->all());
        $record = User::usingSearchString('uid:'.$first->uid.' fields:uid,score')->first();
        $this->assertSame(['uid', 'score'], array_keys($record->getAttributes()));
        $this->assertSame(0, User::usingSearchString('sort:password')->count());
    }

    public function testMalformedSearchCannotCauseAnHttpErrorOrSelectArbitraryColumns(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach ([')', '((', 'score:', ':', 'and', '"unterminated', 'a>', 'score in (',
            'sort:"uid desc; drop table users"', 'register_at:2020-99-99', "register_at:2020\0", str_repeat('not ', 100).'Alice'] as $query) {
            $this->getJson(route('admin.users.list', ['q' => $query]))->assertOk();
        }
        $this->assertSame(1, User::count());
    }
}
