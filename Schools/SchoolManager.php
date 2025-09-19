<?php
/**
 * School Manager for basic school operations
 * 
 * @package SchoolManagement\Schools
 * @since 1.0.0
 */

namespace SchoolManagement\Schools;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for managing schools
 */
class SchoolManager
{
    /**
     * School post type
     */
    private const POST_TYPE = 'coo_school';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHooks();
    }

    /**
     * Initialize WordPress hooks
     */
    public function initHooks(): void
    {
        // Basic school management hooks can be added here
    }

    /**
     * Get school by ID
     * 
     * @param int $school_id School ID
     * @return \WP_Post|null
     */
    public function getSchool(int $school_id): ?\WP_Post
    {
        $school = get_post($school_id);
        
        if (!$school || $school->post_type !== self::POST_TYPE) {
            return null;
        }
        
        return $school;
    }

    /**
     * Get all schools
     * 
     * @param array $args Additional query arguments
     * @return array
     */
    public function getSchools(array $args = []): array
    {
        $default_args = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ];

        $query_args = wp_parse_args($args, $default_args);
        
        return get_posts($query_args);
    }

    /**
     * Get school meta data
     * 
     * @param int $school_id School ID
     * @param string $meta_key Meta key
     * @param bool $single Return single value
     * @return mixed
     */
    public function getSchoolMeta(int $school_id, string $meta_key, bool $single = true)
    {
        return get_post_meta($school_id, $meta_key, $single);
    }

    /**
     * Update school meta data
     * 
     * @param int $school_id School ID
     * @param string $meta_key Meta key
     * @param mixed $meta_value Meta value
     * @return int|bool
     */
    public function updateSchoolMeta(int $school_id, string $meta_key, $meta_value)
    {
        return update_post_meta($school_id, $meta_key, $meta_value);
    }

    /**
     * Get school post type
     * 
     * @return string
     */
    public function getPostType(): string
    {
        return self::POST_TYPE;
    }
}
