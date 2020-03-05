<?php

namespace Gazelle\Schedule\Tasks;

class DemoteUsersRatio extends \Gazelle\Schedule\Task
{
    public function run()
    {
        // TODO: refactor this into the config.
        $this->demote(USER, 0.65, 0, [
            MEMBER, POWER, ELITE, TORRENT_MASTER, POWER_TM, ELITE_TM, ULTIMATE_TM
        ]);
        $this->demote(MEMBER, 0.95, 25 * 1024 * 1024 * 1024, [
            POWER, ELITE, TORRENT_MASTER, POWER_TM, ELITE_TM, ULTIMATE_TM
        ]);
    }

    private function demote(int $newClass, float $ratio, int $upload, array $demoteClasses) {
        $classString = \Users::make_class_string($newClass);
        $placeholders = implode(', ', array_fill(0, count($demoteClasses), '?'));
        $query = $this->db->prepared_query("
            SELECT ID
            FROM users_main um
            INNER JOIN users_leech_stats AS uls ON (uls.UserID = um.ID)
            LEFT JOIN
            (
                SELECT UserID, SUM(Bounty) AS Bounty
                FROM requests_votes
                GROUP BY UserID
            ) b ON (b.UserID = um.ID)
            WHERE um.PermissionID IN ($placeholders)
                AND (
                    (uls.Downloaded > 0 AND (uls.Uploaded + ifnull(b.Bounty, 0)) / uls.Downloaded < ?)
                    OR uls.Uploaded < ?
                )
            ", ...array_merge($demoteClasses, [$ratio, $upload])
        );

        $this->db->prepared_query("
            UPDATE users_info AS ui
            INNER JOIN users_main AS um ON (um.ID = ui.UserID)
            INNER JOIN users_leech_stats AS uls ON (uls.UserID = um.ID)
            LEFT JOIN
            (
                SELECT UserID, SUM(Bounty) AS Bounty
                FROM requests_votes
                GROUP BY UserID
            ) b ON (b.UserID = um.ID)
            SET
                um.PermissionID = ?,
                ui.AdminComment = CONCAT(now(), ' - Class changed to ', ?, ' by System\n\n', ui.AdminComment)
            WHERE um.PermissionID IN ($placeholders)
                AND (
                    (uls.Downloaded > 0 AND (uls.Uploaded + ifnull(b.Bounty, 0)) / uls.Downloaded < ?)
                    OR uls.Uploaded < ?
                )
            ", ...array_merge([$newClass, $classString], $demoteClasses, [$ratio, $upload])
        );

        $this->db->set_query_id($query);
        $demotions = 0;
        while (list($userID) = $this->db->next_record()) {
            $demotions++;
            $this->debug("Demoting $userID to $classString for insufficient ratio", $userID);

            $this->cache->delete_value("user_info_$userID");
            $this->cache->delete_value("user_info_heavy_$userID");
            \Misc::send_pm($userID, 0, "You have been demoted to $classString", "You now only meet the requirements for the \"$classString\" user class.\n\nTo read more about ".SITE_NAME."'s user classes, read [url=".site_url()."wiki.php?action=article&amp;name=userclasses]this wiki article[/url].");
        }

        if ($demotions > 0) {
            $this->processed += $demotions;
            $this->info("Demoted $demotions users to $classString for insufficient ratio", $newClass);
        }
    }
}
