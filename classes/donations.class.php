<?php

class Donations {
    private static $IsSchedule = false;

    public static function regular_donate($UserID, $DonationAmount, $Source, $Reason, $Currency = "EUR") {
        self::donate($UserID, [
            "Reason" => $Reason,
            "Source" => $Source,
            "Price" => $DonationAmount,
            "Currency" => $Currency,
            "SendPM" => true
        ]);
    }

    public static function donate($UserID, array $Args) {
        $UserID = (int)$UserID;
        $QueryID = G::$DB->get_query_id();

        G::$DB->prepared_query('
            SELECT 1
            FROM users_main
            WHERE ID = ?
            ', $UserID
        );
        if (G::$DB->has_results()) {
            G::$Cache->InternalCache = false;

            // Legacy donor, should remove at some point
            G::$DB->prepared_query('
                UPDATE users_info
                SET Donor = ?
                WHERE UserID = ?
                ', '1', $UserID
            );

            // Give them an invite the first time they donate
            $FirstInvite = G::$DB->affected_rows();

            // Assign them to the Donor secondary class
            $DonorClass = G::$DB->scalar('SELECT ID FROM permissions WHERE Name = ?', 'Donor');
            if ($DonorClass) {
                if (!G::$DB->scalar('SELECT 1 FROM users_levels WHERE UserID = ? AND PermissionID = ?', $UserID, $DonorClass)) {
                    G::$DB->prepared_query('
                        INSERT INTO users_levels
                               (UserID, PermissionID)
                        VALUES (?,      ?)
                        ', $UserID, $DonorClass
                    );
                }
            }

            // A staff member is directly manipulating donor points
            if (isset($Args['Manipulation']) && $Args['Manipulation'] === "Direct") {
                $DonorPoints = $Args['Rank'];
                $AdjustedRank = $Args['Rank'] >= MAX_EXTRA_RANK ? MAX_EXTRA_RANK : $Args['Rank'];
                $ConvertedPrice = 0;
                G::$DB->prepared_query('
                    INSERT INTO users_donor_ranks
                           (UserID, Rank, TotalRank, DonationTime, RankExpirationTime)
                    VALUES (?,      ?,    ?,         now(),        now())
                    ON DUPLICATE KEY UPDATE
                        Rank = ?,
                        TotalRank = ?,
                        DonationTime = now(),
                        RankExpirationTime = now()
                    ', $UserID, $AdjustedRank, $Args['TotalRank'],
                        $AdjustedRank, $Args['TotalRank']
                );
            } else {
                $BTC = new \Gazelle\Manager\BTC(G::$DB, G::$Cache);
                $forexRate = $BTC->latestRate('EUR');
                switch ($Args['Currency'] == 'BTC') {
                    case 'BTC':
                        $btcAmount = $Args['Price'];
                        $ConvertedPrice = $Args['Price'] * $forexRate;
                        break;
                    case 'EUR':
                        $btcAmount = $Args['Price'] / $forexRate;
                        $ConvertedPrice = $Args['Price'];
                        break;
                    default:
                        $btcAmount = $BTC->fiat2btc($Args['Price'], $Args['Currency']);
                        $ConvertedPrice = $btcAmount * $forexRate;
                        break;
                }

                // Rank is the same thing as DonorPoints
                // A user's donor rank can never exceed MAX_EXTRA_RANK
                // The total rank isn't affected by this, so their original donor point value is added to it
                $IncreaseRank = $DonorPoints = self::calculate_rank($ConvertedPrice);
                $CurrentRank = self::get_rank($UserID);
                $AdjustedRank = min(MAX_EXTRA_RANK, $CurrentRank + $DonorPoints);

                G::$DB->prepared_query('
                    INSERT INTO users_donor_ranks
                           (UserID, Rank, TotalRank, DonationTime, RankExpirationTime)
                    VALUES (?,      ?,    ?,         now(),        now())
                    ON DUPLICATE KEY UPDATE
                        Rank = ?,
                        TotalRank = TotalRank + ?,
                        DonationTime = now(),
                        RankExpirationTime = now()
                    ', $UserID, $AdjustedRank, $DonorPoints,
                        $AdjustedRank, $DonorPoints
                );
            }
            // Donor cache key is outdated
            G::$Cache->delete_value("donor_info_$UserID");

            // Get their rank
            $Rank = self::get_rank($UserID);
            $TotalRank = self::get_total_rank($UserID);

            // Now that their rank and total rank has been set, we can calculate their special rank
            self::calculate_special_rank($UserID);

            // Hand out invites
            $InvitesReceivedRank = G::$DB->scalar('
                SELECT InvitesReceivedRank
                FROM users_donor_ranks
                WHERE UserID = ?
                ', $UserID
            );
            $AdjustedRank = $Rank >= MAX_RANK ? (MAX_RANK - 1) : $Rank;
            $InviteRank = $AdjustedRank - $InvitesReceivedRank;
            if ($InviteRank > 0) {
                G::$DB->prepared_query('
                    UPDATE users_main
                    SET Invites = Invites + ?
                    WHERE ID = ?
                    ', $FirstInvite + $InviteRank, $UserID
                );
                G::$DB->prepared_query('
                    UPDATE users_donor_ranks
                    SET InvitesReceivedRank = ?
                    WHERE UserID = ?
                    ', $AdjustedRank, $UserID);
            }

            // Send them a thank you PM
            if ($Args['SendPM']) {
                Misc::send_pm(
                    $UserID,
                    0,
                    'Your contribution has been received and credited. Thank you!',
                    self::get_pm_body($Args['Source'], $Args['Currency'], $Args['Price'], $IncreaseRank, $Rank)
                );
            }

            // Lastly, add this donation to our history
            G::$DB->prepared_query('
                INSERT INTO donations
                       (UserID, Amount, Source, Reason, Currency, AddedBy, Rank, TotalRank, btc, Time)
                VALUES (?,      ?,      ?,      ?,      ?,        ?,       ?,    ?,         ?, now())
                ', $UserID, $ConvertedPrice, $Args['Source'], $Args['Reason'], $Args['Currency'],
                    self::$IsSchedule ? 0 : G::$LoggedUser['ID'], $DonorPoints, $TotalRank, $btcAmount
            );

            // Clear their user cache keys because the users_info values has been modified
            G::$Cache->deleteMulti(["user_info_$UserID", "user_info_heavy_$UserID", "donor_info_$UserID"]);
        }
        G::$DB->set_query_id($QueryID);
    }

    private static function calculate_special_rank($UserID) {
        $UserID = (int)$UserID;
        $QueryID = G::$DB->get_query_id();
        // Are they are special?
        G::$DB->prepared_query('
            SELECT TotalRank, SpecialRank
            FROM users_donor_ranks
            WHERE UserID = ?
            ', $UserID
        );
        if (G::$DB->has_results()) {
            // Adjust their special rank depending on the total rank.
            list($TotalRank, $SpecialRank) = G::$DB->next_record();
            if ($TotalRank < 10) {
                $SpecialRank = 0;
            }
            if ($SpecialRank < 1 && $TotalRank >= 10) {
                Misc::send_pm( $UserID, 0,
                    "You have Reached Special Donor Rank #1! You've Earned: One User Pick. Details Inside.",
                    G::$Twig->render('donation/special-rank-1.twig', [
                       'forum_url'   => site_url() . 'forums.php?action=viewthread&threadid=178640&postid=4839790#post4839790',
                       'site_name'   => SITE_NAME,
                       'staffpm_url' => site_url() . 'staffpm.php',
                    ])
                );
                $SpecialRank = 1;
            }
            if ($SpecialRank < 2 && $TotalRank >= 20) {
                Misc::send_pm($UserID, 0,
                    "You have Reached Special Donor Rank #2! You've Earned: The Double-Avatar. Details Inside.",
                    G::$Twig->render('donation/special-rank-2.twig', [
                       'forum_url' => site_url() . 'forums.php?action=viewthread&threadid=178640&postid=4839790#post4839790',
                       'site_name' => SITE_NAME,
                    ])
                );
                $SpecialRank = 2;
            }
            if ($SpecialRank < 3 && $TotalRank >= 50) {
                Misc::send_pm($UserID, 0,
                    "You have Reached Special Donor Rank #3! You've Earned: Diamond Rank. Details Inside.",
                    G::$Twig->render('donation/special-rank-3.twig', [
                       'forum_url'      => site_url() . 'forums.php?action=viewthread&threadid=178640&postid=4839790#post4839790',
                       'forum_gold_url' => site_url() . 'forums.php?action=viewthread&threadid=178640&postid=4839789#post4839789',
                       'site_name'      => SITE_NAME,
                    ])
                );
                $SpecialRank = 3;
            }
            // Make them special
            G::$DB->prepared_query('
                UPDATE users_donor_ranks
                SET SpecialRank = ?
                WHERE UserID = ?
                ', $SpecialRank, $UserID
            );
            G::$Cache->delete_value("donor_info_$UserID");
        }
        G::$DB->set_query_id($QueryID);
    }

    public static function schedule() {
        self::$IsSchedule = true;
        DonationsBitcoin::find_new_donations();
        self::expire_ranks();
    }

    public static function expire_ranks() {
        $QueryID = G::$DB->get_query_id();
        G::$DB->query("
            SELECT UserID, Rank
            FROM users_donor_ranks
            WHERE Rank > 1
                AND SpecialRank != 3
                AND RankExpirationTime < NOW() - INTERVAL 766 HOUR");
                // 2 hours less than 32 days to account for schedule run times

        if (G::$DB->record_count() > 0) {
            $UserIDs = [];
            while (list($UserID, $Rank) = G::$DB->next_record()) {
                G::$Cache->delete_value("donor_info_$UserID");
                G::$Cache->delete_value("donor_title_$UserID");
                G::$Cache->delete_value("donor_profile_rewards_$UserID");
                $UserIDs[] = $UserID;
            }
            $In = implode(',', $UserIDs);
            G::$DB->query("
                UPDATE users_donor_ranks
                SET Rank = Rank - IF(Rank = " . MAX_RANK . ", 2, 1), RankExpirationTime = NOW()
                WHERE UserID IN ($In)");
        }
        G::$DB->set_query_id($QueryID);
    }

    private static function calculate_rank($Amount) {
        return floor($Amount / DONOR_RANK_PRICE);
    }

    public static function update_rank($UserID, $Rank, $TotalRank, $Reason) {
        $Rank = (int)$Rank;
        $TotalRank = (int)$TotalRank;

        self::donate($UserID, [
            "Reason" => $Reason,
            "Source" => "Modify Values",
            "Currency" => "EUR",
            "SendPM" => false,
            "Manipulation" => "Direct",
            "Rank" => $Rank,
            "TotalRank" => $TotalRank
        ]);
    }

    public static function hide_stats($UserID) {
        $QueryID = G::$DB->get_query_id();
        G::$DB->prepared_query('
            INSERT INTO users_donor_ranks
                   (UserID, Hidden)
            VALUES (?,      ?)
            ON DUPLICATE KEY UPDATE
                Hidden = ?
            ', $UserID, '1', '1'
        );
        G::$DB->set_query_id($QueryID);
    }

    public static function show_stats($UserID) {
        $QueryID = G::$DB->get_query_id();
        G::$DB->prepared_query('
            INSERT INTO users_donor_ranks
                   (UserID, Hidden)
            VALUES (?,      ?)
            ON DUPLICATE KEY UPDATE
                Hidden = ?
            ', $UserID, '0', '0'
        );
        G::$DB->set_query_id($QueryID);
    }

    public static function is_visible($UserID) {
        $QueryID = G::$DB->get_query_id();
        G::$DB->prepared_query('
            SELECT Hidden
            FROM users_donor_ranks
            WHERE UserID = ?
                AND Hidden = ?
            ', $UserID, '0'
        );
        $HasResults = G::$DB->has_results();
        G::$DB->set_query_id($QueryID);
        return $HasResults;
    }

    public static function has_donor_forum($UserID) {
        return self::get_rank($UserID) >= DONOR_FORUM_RANK || self::get_special_rank($UserID) >= MAX_SPECIAL_RANK;
    }

    /**
     * Put all the common donor info in the same cache key to save some cache calls
     */
    public static function get_donor_info($UserID) {
        // Our cache class should prevent identical memcached requests
        $DonorInfo = G::$Cache->get_value("donor_info_$UserID");
        if ($DonorInfo === false) {
            $QueryID = G::$DB->get_query_id();
            G::$DB->prepared_query('
                SELECT
                    Rank,
                    SpecialRank,
                    TotalRank,
                    DonationTime,
                    RankExpirationTime + INTERVAL 766 HOUR
                FROM users_donor_ranks
                WHERE UserID = ?
                ', $UserID
            );
                // 2 hours less than 32 days to account for schedule run times
            if (G::$DB->has_results()) {
                list($Rank, $SpecialRank, $TotalRank, $DonationTime, $ExpireTime) = G::$DB->next_record(MYSQLI_NUM, false);
                if ($DonationTime === null) {
                    $DonationTime = 0;
                }
                if ($ExpireTime === null) {
                    $ExpireTime = 0;
                }
            } else {
                $Rank = $SpecialRank = $TotalRank = $DonationTime = $ExpireTime = 0;
            }
            if (Permissions::is_mod($UserID)) {
                $Rank = MAX_EXTRA_RANK;
                $SpecialRank = MAX_SPECIAL_RANK;
            }
            G::$DB->prepared_query('
                SELECT
                    IconMouseOverText,
                    AvatarMouseOverText,
                    CustomIcon,
                    CustomIconLink,
                    SecondAvatar
                FROM donor_rewards
                WHERE UserID = ?
                ', $UserID
            );
            $Rewards = G::$DB->next_record(MYSQLI_ASSOC);
            G::$DB->set_query_id($QueryID);

            $DonorInfo = [
                'Rank' => (int)$Rank,
                'SRank' => (int)$SpecialRank,
                'TotRank' => (int)$TotalRank,
                'Time' => $DonationTime,
                'ExpireTime' => $ExpireTime,
                'Rewards' => $Rewards
            ];
            G::$Cache->cache_value("donor_info_$UserID", $DonorInfo, 0);
        }
        return $DonorInfo;
    }

    public static function get_rank($UserID) {
        return self::get_donor_info($UserID)['Rank'];
    }

    public static function get_special_rank($UserID) {
        return self::get_donor_info($UserID)['SRank'];
    }

    public static function get_total_rank($UserID) {
        return self::get_donor_info($UserID)['TotRank'];
    }

    public static function get_donation_time($UserID) {
        return self::get_donor_info($UserID)['Time'];
    }

    public static function get_personal_collages($UserID) {
        $DonorInfo = self::get_donor_info($UserID);
        if ($DonorInfo['SRank'] == MAX_SPECIAL_RANK) {
            $Collages = 5;
        } else {
            $Collages = min($DonorInfo['Rank'], 5); // One extra collage per donor rank up to 5
        }
        return $Collages;
    }

    public static function get_titles($UserID) {
        $Results = G::$Cache->get_value("donor_title_$UserID");
        if ($Results === false) {
            $QueryID = G::$DB->get_query_id();
            G::$DB->prepared_query('
                SELECT Prefix, Suffix, UseComma
                FROM donor_forum_usernames
                WHERE UserID = ?
                ', $UserID
            );
            $Results = G::$DB->next_record();
            G::$DB->set_query_id($QueryID);
            G::$Cache->cache_value("donor_title_$UserID", $Results, 0);
        }
        return $Results;
    }

    public static function get_enabled_rewards($UserID) {
        $Rewards = [];
        $Rank = self::get_rank($UserID);
        $SpecialRank = self::get_special_rank($UserID);
        $HasAll = $SpecialRank == 3;

        $Rewards = [
            'HasAvatarMouseOverText' => false,
            'HasCustomDonorIcon' => false,
            'HasDonorForum' => false,
            'HasDonorIconLink' => false,
            'HasDonorIconMouseOverText' => false,
            'HasProfileInfo1' => false,
            'HasProfileInfo2' => false,
            'HasProfileInfo3' => false,
            'HasProfileInfo4' => false,
            'HasSecondAvatar' => false
        ];

        if ($Rank >= 2 || $HasAll) {
            $Rewards["HasDonorIconMouseOverText"] = true;
            $Rewards["HasProfileInfo1"] = true;
        }
        if ($Rank >= 3 || $HasAll) {
            $Rewards["HasAvatarMouseOverText"] = true;
            $Rewards["HasProfileInfo2"] = true;
        }
        if ($Rank >= 4 || $HasAll) {
            $Rewards["HasDonorIconLink"] = true;
            $Rewards["HasProfileInfo3"] = true;
        }
        if ($Rank >= MAX_RANK || $HasAll) {
            $Rewards["HasCustomDonorIcon"] = true;
            $Rewards["HasDonorForum"] = true;
            $Rewards["HasProfileInfo4"] = true;
        }
        if ($SpecialRank >= 2) {
            $Rewards["HasSecondAvatar"] = true;
        }
        return $Rewards;
    }

    public static function get_rewards($UserID) {
        return self::get_donor_info($UserID)['Rewards'];
    }

    public static function get_profile_rewards($UserID) {
        $Results = G::$Cache->get_value("donor_profile_rewards_$UserID");
        if ($Results === false) {
            $QueryID = G::$DB->get_query_id();
            G::$DB->prepared_query('
                SELECT
                    ProfileInfo1,
                    ProfileInfoTitle1,
                    ProfileInfo2,
                    ProfileInfoTitle2,
                    ProfileInfo3,
                    ProfileInfoTitle3,
                    ProfileInfo4,
                    ProfileInfoTitle4
                FROM donor_rewards
                WHERE UserID = ?
                ', $UserID
            );
            $Results = G::$DB->next_record();
            G::$DB->set_query_id($QueryID);
            G::$Cache->cache_value("donor_profile_rewards_$UserID", $Results, 0);
        }
        return $Results;
    }

    private static function add_profile_info_reward($Counter, &$Insert, &$Values, &$Update) {
        if (isset($_POST["profile_title_" . $Counter]) && isset($_POST["profile_info_" . $Counter])) {
            $ProfileTitle = db_string($_POST["profile_title_" . $Counter]);
            $ProfileInfo = db_string($_POST["profile_info_" . $Counter]);
            $ProfileInfoTitleSQL = "ProfileInfoTitle" . $Counter;
            $ProfileInfoSQL = "ProfileInfo" . $Counter;
            $Insert[] = "$ProfileInfoTitleSQL";
            $Values[] = "'$ProfileInfoTitle'";
            $Update[] = "$ProfileInfoTitleSQL = '$ProfileTitle'";
            $Insert[] = "$ProfileInfoSQL";
            $Values[] = "'$ProfileInfo'";
            $Update[] = "$ProfileInfoSQL = '$ProfileInfo'";
        }
    }

    public static function update_rewards($UserID) {
        $Rank = self::get_rank($UserID);
        $SpecialRank = self::get_special_rank($UserID);
        $HasAll = $SpecialRank == 3;
        $Counter = 0;
        $Insert = [];
        $Values = [];
        $Update = [];

        $Insert[] = "UserID";
        $Values[] = "'$UserID'";
        if ($Rank >= 1 || $HasAll) {

        }
        if ($Rank >= 2 || $HasAll) {
            if (isset($_POST['donor_icon_mouse_over_text'])) {
                $IconMouseOverText = db_string($_POST['donor_icon_mouse_over_text']);
                $Insert[] = "IconMouseOverText";
                $Values[] = "'$IconMouseOverText'";
                $Update[] = "IconMouseOverText = '$IconMouseOverText'";
            }
            $Counter++;
        }
        if ($Rank >= 3 || $HasAll) {
            if (isset($_POST['avatar_mouse_over_text'])) {
                $AvatarMouseOverText = db_string($_POST['avatar_mouse_over_text']);
                $Insert[] = "AvatarMouseOverText";
                $Values[] = "'$AvatarMouseOverText'";
                $Update[] = "AvatarMouseOverText = '$AvatarMouseOverText'";
            }
            $Counter++;
        }
        if ($Rank >= 4 || $HasAll) {
            if (isset($_POST['donor_icon_link'])) {
                $CustomIconLink = db_string($_POST['donor_icon_link']);
                if (!Misc::is_valid_url($CustomIconLink)) {
                    $CustomIconLink = '';
                }
                $Insert[] = "CustomIconLink";
                $Values[] = "'$CustomIconLink'";
                $Update[] = "CustomIconLink = '$CustomIconLink'";
            }
            $Counter++;
        }
        if ($Rank >= MAX_RANK || $HasAll) {
            if (isset($_POST['donor_icon_custom_url'])) {
                $CustomIcon = db_string($_POST['donor_icon_custom_url']);
                if (!Misc::is_valid_url($CustomIcon)) {
                    $CustomIcon = '';
                }
                $Insert[] = "CustomIcon";
                $Values[] = "'$CustomIcon'";
                $Update[] = "CustomIcon = '$CustomIcon'";
            }
            self::update_titles($UserID, $_POST['donor_title_prefix'], $_POST['donor_title_suffix'], $_POST['donor_title_comma']);
            $Counter++;
        }
        for ($i = 1; $i <= $Counter; $i++) {
            self::add_profile_info_reward($i, $Insert, $Values, $Update);
        }
        if ($SpecialRank >= 2) {
            if (isset($_POST['second_avatar'])) {
                $SecondAvatar = db_string($_POST['second_avatar']);
                if (!Misc::is_valid_url($SecondAvatar)) {
                    $SecondAvatar = '';
                }
                $Insert[] = "SecondAvatar";
                $Values[] = "'$SecondAvatar'";
                $Update[] = "SecondAvatar = '$SecondAvatar'";
            }
        }
        $Insert = implode(', ', $Insert);
        $Values = implode(', ', $Values);
        $Update = implode(', ', $Update);
        if ($Counter > 0) {
            $QueryID = G::$DB->get_query_id();
            G::$DB->query("
                INSERT INTO donor_rewards
                    ($Insert)
                VALUES
                    ($Values)
                ON DUPLICATE KEY UPDATE
                    $Update");
            G::$DB->set_query_id($QueryID);
        }
        G::$Cache->delete_value("donor_profile_rewards_$UserID");
        G::$Cache->delete_value("donor_info_$UserID");

    }

    // TODO: make $UseComma more sane
    public static function update_titles($UserID, $Prefix, $Suffix, $UseComma) {
        $QueryID = G::$DB->get_query_id();
        $Prefix = trim($Prefix);
        $Suffix = trim($Suffix);
        $UseComma = empty($UseComma) ? true : false;
        G::$DB->prepared_query('
            INSERT INTO donor_forum_usernames
                   (UserID, Prefix, Suffix, UseComma)
            VALUES (?,      ?,      ?,      ?)
            ON DUPLICATE KEY UPDATE
                Prefix = ?, Suffix = ?, UseComma = ?
            ', $UserID, $Prefix, $Suffix, $UseComma !== null ? 1 : 0,
                $Prefix, $Suffix, $UseComma !== null ? 1 : 0
        );
        G::$Cache->delete_value("donor_title_$UserID");
        G::$DB->set_query_id($QueryID);
    }

    public static function get_donation_history($UserID) {
        $UserID = (int)$UserID;
        if (empty($UserID)) {
            error(404);
        }
        $QueryID = G::$DB->get_query_id();
        G::$DB->prepared_query('
            SELECT Amount, Time, Currency, Reason, Source, AddedBy, Rank, TotalRank
            FROM donations
            WHERE UserID = ?
            ORDER BY Time DESC
            ', $UserID
        );
        $DonationHistory = G::$DB->to_array(false, MYSQLI_ASSOC, false);
        G::$DB->set_query_id($QueryID);
        return $DonationHistory;
    }

    public static function get_rank_expiration($UserID) {
        $DonorInfo = self::get_donor_info($UserID);
        if ($DonorInfo['SRank'] == MAX_SPECIAL_RANK || $DonorInfo['Rank'] == 1) {
            $Return = 'Never';
        } elseif ($DonorInfo['ExpireTime']) {
            $ExpireTime = strtotime($DonorInfo['ExpireTime']);
            if ($ExpireTime - time() < 60) {
                $Return = 'Soon';
            } else {
                $Expiration = time_diff($ExpireTime); // 32 days
                $Return = "in $Expiration";
            }
        } else {
            $Return = '';
        }
        return $Return;
    }

    public static function get_leaderboard_position($UserID) {
        $UserID = (int)$UserID;
        $QueryID = G::$DB->get_query_id();
        G::$DB->query("SET @RowNum := 0");
        G::$DB->query("
            SELECT Position
            FROM (
                SELECT d.UserID, @RowNum := @RowNum + 1 AS Position
                FROM users_donor_ranks AS d
                ORDER BY TotalRank DESC
            ) l
            WHERE UserID = '$UserID'");
        if (G::$DB->has_results()) {
            list($Position) = G::$DB->next_record();
        } else {
            $Position = 0;
        }
        G::$DB->set_query_id($QueryID);
        return $Position;
    }

    public static function is_donor($UserID) {
        return self::get_rank($UserID) > 0;
    }

    private static function get_pm_body($Source, $Currency, $DonationAmount, $ReceivedRank, $CurrentRank) {
        if ($Currency != 'BTC') {
            $DonationAmount = number_format($DonationAmount, 2);
        }
        if ($CurrentRank >= MAX_RANK) {
            $CurrentRank = MAX_RANK - 1;
        } elseif ($CurrentRank == 5) {
            $CurrentRank = 4;
        }
        return G::$Twig->render('donation/donation-pm.twig', [
            'amount' => $DonationAmount,
            'cc'     => $Currency,
            'points' => $ReceivedRank,
            's'      => $ReceivedRank == 1 ? '' : 's',
            'rank'   => $CurrentRank,
            'staffpm_url' => site_url() . 'staffpm.php',
        ]);
    }
}
