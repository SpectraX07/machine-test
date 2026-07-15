<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Single-role RBAC: users.role_id replaces the user_roles pivot.
 */
class AddRoleIdToUsersAndDropUserRoles extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('role_id', 'users')) {
            $this->forge->addColumn('users', [
                'role_id' => [
                    'type'     => 'BIGINT',
                    'unsigned' => true,
                    'null'     => true,
                ],
            ]);
        }

        if ($this->db->tableExists('user_roles')) {
            $rows = $this->db->table('user_roles')
                ->orderBy('role_id', 'ASC')
                ->get()
                ->getResult();

            foreach ($rows as $row) {
                $this->db->table('users')
                    ->where('id', $row->user_id)
                    ->where('role_id', null)
                    ->update(['role_id' => $row->role_id]);
            }

            $this->forge->dropTable('user_roles', true);
        }
    }

    public function down()
    {
        if (! $this->db->tableExists('user_roles')) {
            $this->forge->addField([
                'user_id' => [
                    'type'     => 'BIGINT',
                    'unsigned' => true,
                ],
                'role_id' => [
                    'type'     => 'BIGINT',
                    'unsigned' => true,
                ],
            ]);
            $this->forge->addKey(['user_id', 'role_id'], true);
            $this->forge->addKey('role_id');
            $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('role_id', 'roles', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('user_roles');
        }

        if ($this->db->fieldExists('role_id', 'users')) {
            $users = $this->db->table('users')
                ->select('id, role_id')
                ->where('role_id IS NOT NULL')
                ->get()
                ->getResult();

            foreach ($users as $user) {
                $this->db->table('user_roles')->insert([
                    'user_id' => $user->id,
                    'role_id' => $user->role_id,
                ]);
            }

            $this->forge->dropColumn('users', 'role_id');
        }
    }
}
