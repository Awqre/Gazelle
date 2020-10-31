<?php

use Phinx\Migration\AbstractMigration;

class UserSeedboxDropPeerId extends AbstractMigration {
    public function up() {
        $this->table('user_seedbox')
            ->removeColumn('peer_id')
            ->update();
    }

    public function down() {
        $this->table('user_seedbox')
            ->addColumn('peer_id',   'binary',  ['limit' => 20])
            ->update();
    }
}
