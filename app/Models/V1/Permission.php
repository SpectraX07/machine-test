<?php

namespace App\Models\V1;

use CodeIgniter\Model;

class Permission extends Model
{
    protected $table            = 'permissions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['name', 'slug', 'description'];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function findBySlug(string $slug): ?object
    {
        return $this->where('slug', $slug)->first();
    }

    /**
     * @return list<object>
     */
    public function findBySlugs(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return $this->whereIn('slug', array_values(array_unique($slugs)))->findAll();
    }

    public function toPublic(object $permission): object
    {
        return (object) [
            'id'          => (int) $permission->id,
            'name'        => $permission->name,
            'slug'        => $permission->slug,
            'description' => $permission->description,
            'created_at'  => $permission->created_at ?? null,
            'updated_at'  => $permission->updated_at ?? null,
        ];
    }
}
