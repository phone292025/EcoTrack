<?php
/**
 * Read-only report queries.
 */
final class ReportsTest extends TestCase
{
    public function testParticipantDirectoryFiguresAndPaging(): void
    {
        $top = Fixtures::user('top_scorer', 'participant', 90);
        Fixtures::user('middle', 'participant', 40);
        Fixtures::user('newcomer');

        $mod = Fixtures::moderatorId();
        reviewSubmission($mod, false, Fixtures::pendingLog($top), 'approve', '');
        reviewSubmission($mod, false, Fixtures::pendingLog($top), 'approve', '');
        Fixtures::checkin($top, 0);

        $page = getParticipantDirectory('', 1, 2);
        $this->assertSame(3, $page['total']);
        $this->assertSame(2, $page['pages']);
        $this->assertSame(['top_scorer', 'middle'], array_column($page['rows'], 'username'));

        $first = $page['rows'][0];
        $this->assertSame(2, (int)$first['approved_logs']);
        $this->assertSame(dbToday(), $first['last_checkin']);
        $this->assertTrue((int)$first['badge_count'] > 0, 'points badges were earned');

        $last = getParticipantDirectory('', 2, 2);
        $this->assertSame(['newcomer'], array_column($last['rows'], 'username'));
        $this->assertSame(0, (int)$last['rows'][0]['approved_logs']);
    }

    public function testOutOfRangePageIsClamped(): void
    {
        Fixtures::user('only_one');

        $page = getParticipantDirectory('', 99, 25);
        $this->assertSame(1, $page['page']);
        $this->assertSame(['only_one'], array_column($page['rows'], 'username'));
    }

    public function testSearchTreatsWildcardsLiterally(): void
    {
        Fixtures::user('under_score');
        Fixtures::user('underxscore');

        $this->assertSame(['under_score'], array_column(getParticipantDirectory('under_s', 1, 25)['rows'], 'username'));
        $this->assertSame(0, getParticipantDirectory('%', 1, 25)['total']);
    }
}
