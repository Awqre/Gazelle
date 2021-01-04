<?php

namespace Gazelle\UserRank\Dimension;

class BonusPoints extends \Gazelle\UserRank\AbstractUserRank {

    public function cacheKey(): string {
        return 'rank_data_bonuspoint';
    }

    public function selector(): string {
        return "
            SELECT DISTINCT n FROM (
                SELECT sum(bh.Price) AS n
                FROM bonus_history bh
                INNER JOIN users_main AS um ON (um.ID = bh.UserID)
                GROUP BY UserID
            ) C
            ORDER BY 1
            ";
    }
}
