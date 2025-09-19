<?php
/**
 * Shop Manager Role Manager
 * 
 * Añade capacidades de gestión de usuarios al rol shop_manager
 * 
 * @package SchoolManagement\Users
 */

namespace SchoolManagement\Users;

if (!defined('ABSPATH')) {
    exit;
}

class ShopManagerRoleManager
{
    public function __construct()
    {
        add_action('init', [$this, 'addUserCapabilities']);
        add_filter('map_meta_cap', [$this, 'mapCapabilities'], 10, 4);
        add_filter('editable_roles', [$this, 'filterEditableRoles'], 1);
        add_filter('user_has_cap', [$this, 'allowRoleField'], 10, 4);
        add_filter('woocommerce_shop_manager_editable_roles', [$this, 'expandEditableRoles']);
        add_action('admin_init', [$this, 'ensureCapabilities']);
    }

    public function addUserCapabilities(): void
    {
        $role = get_role('shop_manager');
        if (!$role) return;

        $capabilities = ['list_users', 'create_users', 'edit_users', 'delete_users', 'promote_users'];
        foreach ($capabilities as $cap) {
            if (!$role->has_cap($cap)) {
                $role->add_cap($cap);
            }
        }
    }

    public function mapCapabilities($caps, $cap, $user_id, $args): array
    {
        $current_user = wp_get_current_user();
        if (!$current_user || !in_array('shop_manager', $current_user->roles)) {
            return $caps;
        }

        switch ($cap) {
            case 'edit_user':
            case 'edit_users':
                if (isset($args[0])) {
                    $target_user = get_userdata((int) $args[0]);
                    if ($target_user && in_array('administrator', $target_user->roles)) {
                        $caps = ['do_not_allow'];
                    } else {
                        $caps = ['edit_users'];
                    }
                } else {
                    $caps = ['edit_users'];
                }
                break;
                
            case 'promote_user':
            case 'promote_users':
                if (isset($args[0])) {
                    $target_user = get_userdata((int) $args[0]);
                    if ($target_user && in_array('administrator', $target_user->roles)) {
                        $caps = ['do_not_allow'];
                    } else {
                        $caps = ['promote_users'];
                    }
                } else {
                    $caps = ['promote_users'];
                }
                break;
                
            case 'delete_user':
            case 'delete_users':
                if (isset($args[0])) {
                    $target_user = get_userdata((int) $args[0]);
                    if ($target_user && (in_array('administrator', $target_user->roles) || $args[0] == $current_user->ID)) {
                        $caps = ['do_not_allow'];
                    } else {
                        $caps = ['delete_users'];
                    }
                } else {
                    $caps = ['delete_users'];
                }
                break;
        }

        return $caps;
    }

    public function filterEditableRoles($roles): array
    {
        $current_user = wp_get_current_user();
        
        if (!$current_user || !in_array('shop_manager', $current_user->roles)) {
            return $roles;
        }
        
        if (in_array('administrator', $current_user->roles)) {
            return $roles;
        }

        $filtered_roles = $roles;
        unset($filtered_roles['administrator']);
        unset($filtered_roles['editor']);
        unset($filtered_roles['author']);
        unset($filtered_roles['contributor']);
        unset($filtered_roles['subscriber']);
        unset($filtered_roles['customer']);
        unset($filtered_roles['translator']);
        return $filtered_roles;
    }


    public function allowRoleField($allcaps, $caps, $args, $user): array
    {
        if (!isset($user->roles) || !in_array('shop_manager', $user->roles)) {
            return $allcaps;
        }

        global $pagenow;
        if (!in_array($pagenow, ['user-edit.php', 'profile.php', 'user-new.php'])) {
            return $allcaps;
        }

        if (in_array('promote_users', $caps)) {
            $allcaps['promote_users'] = true;
        }

        $allcaps['edit_users'] = true;
        $allcaps['list_users'] = true;
        return $allcaps;
    }

    public function ensureCapabilities(): void
    {
        if (!is_admin()) return;
        
        $current_user = wp_get_current_user();
        if (!$current_user || !in_array('shop_manager', $current_user->roles)) {
            return;
        }
        
        global $pagenow;
        if (!in_array($pagenow, ['user-edit.php', 'profile.php', 'user-new.php', 'users.php'])) {
            return;
        }
        
        if (in_array('shop_manager', $current_user->roles)) {
            $current_user->allcaps['edit_users'] = true;
            $current_user->allcaps['promote_users'] = true;
            $current_user->allcaps['list_users'] = true;
            $current_user->allcaps['create_users'] = true;
            $current_user->allcaps['delete_users'] = true;
        }
    }

    public function expandEditableRoles(array $roles): array
    {
        $all_roles = wp_roles()->get_names();
        unset($all_roles['administrator']);
        return array_keys($all_roles);
    }
}