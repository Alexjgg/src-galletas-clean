<?php
/**
 * Simple User Admin Columns Manager
 * 
 * Versión simplificada para debug
 */

namespace SchoolManagement\Users;

if (!defined('ABSPATH')) {
    exit;
}

class SimpleUserAdminColumnsManager
{
    private const POST_TYPE = 'coo_school';

    public function __construct()
    {
        add_filter('manage_users_columns', [$this, 'addCustomColumnsToUsersList']);
        add_filter('manage_users_custom_column', [$this, 'displayCustomColumnContent'], 10, 3);
    }

    public function addCustomColumnsToUsersList(array $columns): array
    {
        $new_columns = [];
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if ($key === 'name') {
                $new_columns['simple_student'] = '👨‍🎓 Estudiante';
                $new_columns['simple_school'] = '🏫 Colegio';
                $new_columns['simple_number'] = '🔢 Número';
            }
        }
        
        return $new_columns;
    }

    public function displayCustomColumnContent(string $value, string $column_name, int $user_id): string
    {
        switch ($column_name) {
            case 'simple_student':
                $user_name = get_field('user_name', 'user_' . $user_id);
                $first_surname = get_field('user_first_surname', 'user_' . $user_id);
                
                if (!$user_name && !$first_surname) {
                    return '<span style="color: #999;">No definido</span>';
                }
                
                return esc_html(trim($user_name . ' ' . $first_surname));
                
            case 'simple_school':
                $school_id = get_user_meta($user_id, 'school_id', true);
                
                if (!$school_id) {
                    return '<span style="color: #d63638;">Sin colegio</span>';
                }
                
                $school_title = get_the_title($school_id);
                return $school_title ? esc_html($school_title) : '<span style="color: #d63638;">Colegio no válido</span>';
                
            case 'simple_number':
                $user_number = get_user_meta($user_id, 'user_number', true);
                return $user_number ? '<strong>' . esc_html($user_number) . '</strong>' : '—';
                
            default:
                return $value;
        }
    }
}
