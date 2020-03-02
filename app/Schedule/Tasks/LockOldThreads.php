<?php

namespace Gazelle\Schedule\Tasks;

class LockOldThreads extends \Gazelle\Schedule\Task
{
    public function run()
    {
        $this->db->prepared_query("
            SELECT t.ID, t.ForumID
            FROM forums_topics AS t
            INNER JOIN forums AS f ON (t.ForumID = f.ID)
            WHERE t.IsLocked = '0'
                AND t.IsSticky = '0'
                AND DATEDIFF(CURDATE(), DATE(t.LastPostTime)) / 7 > f.AutoLockWeeks
                AND f.AutoLock = '1'");
        $ids = $this->db->collect('ID');
        $forumIDs = $this->db->collect('ForumID');

        if (count($ids) > 0) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $this->db->prepared_query("
                UPDATE forums_topics
                SET IsLocked = '1'
                WHERE ID IN ($placeholders)
            ", ...$ids);

            $this->db->prepared_query("
                DELETE FROM forums_last_read_topics
                WHERE TopicID IN ($placeholders)
            ", ...$ids);

            foreach ($ids as $id) {
                $cache->begin_transaction("thread_$id".'_info');
                $cache->update_row(false, ['IsLocked' => '1']);
                $cache->commit_transaction(3600 * 24 * 30);
                $cache->expire_value("thread_$id".'_catalogue_0', 3600 * 24 * 30);
                $cache->expire_value("thread_$id".'_info', 3600 * 24 * 30);
                \Forums::add_topic_note($id, 'Locked automatically by schedule', 0);

                $this->processed++;
            }

            $forumIDs = array_flip(array_flip($forumIDs));
            foreach ($forumIDs as $forumID) {
                $cache->delete_value("forums_$forumID");
            }
        }
    }
}
