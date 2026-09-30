<?php

namespace Database\Seeders;

use App\Models\MenuAllocation;
use App\Models\MenuInfo;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MenuSeeder extends Seeder
{
    /**
     * Hierarchical menu structure.
     * Parent nodes have `url => null` — they're just groupers.
     * Children have `url` set — they point to a route.
     */
    private array $tree = [
        [
            'name'     => 'Dashboard',
            'url'      => 'dashboard',
            'icon'     => 'fas fa-gauge',
            'children' => [],
        ],
        [
            'name'     => 'Menu Management',
            'url'      => null,
            'icon'     => 'fas fa-list-check',
            'children' => [
                [
                    'name' => 'Add Menu For Super Admin',
                    'url'  => 'add-menu',
                    'icon' => 'fas fa-plus-square',
                ],
                [
                    'name' => 'Add Menu',
                    'url'  => 'menu-manage',
                    'icon' => 'fas fa-square-plus',
                ],
                [
                    'name' => 'Assign Menu',
                    'url'  => 'assign-menu',
                    'icon' => 'fas fa-diagram-project',
                ],
            ],
        ],
        [
            'name'     => 'User Management',
            'url'      => null,
            'icon'     => 'fas fa-users',
            'children' => [
                [
                    'name' => 'Users',
                    'url'  => 'users',
                    'icon' => 'fas fa-user-group',
                ],
                [
                    'name' => 'User Types',
                    'url'  => 'user-types',
                    'icon' => 'fas fa-user-cog',
                ],
                [
                    'name' => 'User Details',
                    'url'  => 'user-details',
                    'icon' => 'fas fa-id-card',
                ],
            ],
        ],
        [
            'name'     => 'Company Management',
            'url'      => null,
            'icon'     => 'fas fa-building',
            'children' => [
                [
                    'name' => 'Company',
                    'url'  => 'company',
                    'icon' => 'fas fa-city',
                ],
                [
                    'name' => 'Company Type',
                    'url'  => 'company-types',
                    'icon' => 'fas fa-building-circle-check',
                ],
            ],
        ],
    ];

    public function run(): void
    {
        $userId = 1;
        $user   = User::find($userId);

        if (!$user) {
            $this->command->error("User id={$userId} not found. Aborting.");
            return;
        }

        $created = 0;
        $assigned = 0;

        DB::transaction(function () use ($user, &$created, &$assigned) {
            $parentOrder = 0;

            foreach ($this->tree as $parent) {
                $parentOrder++;

                // ── Insert (or update) the parent ──
                $parentInfo = MenuInfo::updateOrCreate(
                    // Match by menu (parents have no URL). Add `parent_id => null`
                    // so we don't clash with a child of the same name.
                    ['menu' => $parent['name'], 'parent_id' => null],
                    [
                        'menu_url'   => $parent['url'],
                        'icon'       => $parent['icon'],
                        'type'       => 'Menu',
                        'status'     => 'On',
                        'menu_order' => $parentOrder,
                        'created_by' => $user->id,
                    ]
                );

                $this->assignTo($parentInfo, $user, $parentOrder, $created, $assigned);

                // ── Insert each child ──
                $childOrder = 0;
                foreach ($parent['children'] as $child) {
                    $childOrder++;

                    $childInfo = MenuInfo::updateOrCreate(
                        ['menu' => $child['name'], 'parent_id' => $parentInfo->id],
                        [
                            'menu_url'   => $child['url'],
                            'icon'       => $child['icon'],
                            'type'       => 'Menu',
                            'status'     => 'On',
                            'menu_order' => $childOrder,
                            'created_by' => $user->id,
                        ]
                    );

                    $this->assignTo($childInfo, $user, $childOrder, $created, $assigned);
                }
            }
        });

        $this->command->info("✅ Seeded {$created} new menu(s), activated/assigned {$assigned} allocation(s) for user id={$user->id} ({$user->email}).");
    }

    /**
     * Ensure the given menu is assigned (active) to the given user.
     */
    private function assignTo(MenuInfo $menu, User $user, int $priority, int &$created, int &$assigned): void
    {
        // Was it newly created in this run?
        if ($menu->wasRecentlyCreated) $created++;

        $existing = MenuAllocation::where('menu_info_id', $menu->id)
            ->where('user_info_id', $user->id)
            ->first();

        if ($existing) {
            if ($existing->status !== 'active') {
                $existing->update(['status' => 'active']);
            }
            $assigned++;
            return;
        }

        MenuAllocation::create([
            'menu_info_id' => $menu->id,
            'user_info_id' => $user->id,
            'user_type_id' => null,
            'priority'     => $priority,
            'status'       => 'active',
            'created_by'   => $user->id,
        ]);

        $assigned++;
    }
}