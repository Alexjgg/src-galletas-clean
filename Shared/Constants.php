<?php
/**
 * Constantes compartidas para estados, meta keys y campos ACF
 *
 * @package SchoolManagement\Shared
 */

namespace SchoolManagement\Shared;

if (!defined('ABSPATH')) {
    exit;
}

class Constants
{
    // Estados personalizados de pedidos
    public const STATUS_MASTER_ORDER = 'master-order';
    public const STATUS_MASTER_ORDER_COMPLETE = 'master-order-complete';
    public const STATUS_PAY_LATER = 'pay-later';
    public const STATUS_REVIEWED = 'reviewed';

    // Meta keys de pedidos
    public const ORDER_META_SCHOOL_ID = '_school_id';
    public const ORDER_META_VENDOR_ID = '_vendor_id';

    // Campos ACF
    public const ACF_FIELD_SCHOOL_BILLING = 'the_billing_by_the_school';
    public const ACF_FIELD_VENDOR = 'vendor';
    public const ACF_FIELD_SCHOOL_KEY = 'school_key';
    
    // Campos ACF para usuarios
    public const ACF_USER_NAME = 'user_name';
    public const ACF_USER_FIRST_SURNAME = 'user_first_surname';
    public const ACF_USER_SECOND_SURNAME = 'user_second_surname';
    public const ACF_USER_NUMBER = 'user_number';
    
    // Campos ACF para escuelas
    public const ACF_SCHOOL_PROVINCE = 'province';
    public const ACF_SCHOOL_CITY = 'city';
    
    // Meta keys de usuarios
    public const USER_META_SCHOOL_ID = 'school_id';
    public const USER_META_USER_NUMBER = 'user_number';
    
    // Post types
    public const POST_TYPE_SCHOOL = 'coo_school';
    
    // CSS classes y IDs
    public const CSS_CLASS_SCHOOL_FILTER = 'school-filter-select2';
    public const CSS_HANDLE_USER_ADMIN_COLUMNS = 'user-admin-columns';
    public const CSS_HANDLE_SELECT2 = 'select2-css';
    public const JS_HANDLE_SELECT2 = 'select2-js';
    
    // Filtros y acciones
    public const FILTER_DATE_TODAY = 'today';
    public const FILTER_DATE_WEEK = 'week';
    public const FILTER_DATE_MONTH = 'month';
    
    // Columnas personalizadas
    public const COLUMN_STUDENT_INFO = 'student_info';
    public const COLUMN_SCHOOL_INFO = 'school_info';
    public const COLUMN_USER_NUMBER = 'user_number';
    public const COLUMN_REGISTRATION_DATE = 'registration_date';
}
