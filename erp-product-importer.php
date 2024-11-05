<?php
/*
Plugin Name: ERP Product CSV Importer
Description: Imports ERP products from CSV and creates/updates custom post types
Version: 1.0
Author: Mark Fenske
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Register Custom Post Type
function register_erp_product_post_type() {
    $args = array(
        'public' => true,
        'label'  => 'ERP Products',
        'supports' => array('title'),
        'menu_icon' => 'dashicons-products',
    );
    register_post_type('erp_product', $args);
}
add_action('init', 'register_erp_product_post_type');

// Add admin menu
function erp_product_importer_menu() {
    add_menu_page(
        'ERP Product Importer',
        'ERP Importer',
        'manage_options',
        'erp-product-importer',
        'erp_product_importer_page',
        'dashicons-upload'
    );
}
add_action('admin_menu', 'erp_product_importer_menu');

// Create admin page
function erp_product_importer_page() {
    // Handle delete all action
    if (isset($_POST['delete_all_products']) && isset($_POST['delete_all_nonce'])) {
        if (wp_verify_nonce($_POST['delete_all_nonce'], 'delete_all_products')) {
            $deleted = delete_all_erp_products();
            if ($deleted) {
                echo '<div class="updated"><p>All products have been deleted successfully!</p></div>';
            }
        }
    }
    ?>
    <style>
        .card {
            max-width: 100%;
        }
    </style>
    <div class="wrap">
        <h1>ERP Product Importer</h1>
        
        <!-- Import Form -->
        <div class="card">
            <h2>Import Products</h2>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('erp_product_import', 'erp_product_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="import_file">Upload CSV File</label></th>
                        <td>
                            <input type="file" name="import_file" id="import_file" accept=".csv" required>
                            <br><br>
                            <p class="description">Upload a CSV file containing the following fields: <br><br><strong>Product_Location_Id | Product_ProductLine_Id | Product_PartNumber | Product_Description | Product_Quantity | Product_UOM | Product_NetPrice</strong></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Import Products'); ?>
            </form>
        </div>

        <!-- Delete All Form -->
        <div class="card" style="margin-top: 20px; background: #fff0f0; border-left-color: #dc3232;">
            <h2>Delete All Products</h2>
            <p class="description" style="color: #dc3232;">Warning: This action cannot be undone!</p>
            <form method="post" id="delete-all-form">
                <?php wp_nonce_field('delete_all_products', 'delete_all_nonce'); ?>
                <input type="hidden" name="delete_all_products" value="1">
                <?php submit_button('Delete All Products', 'delete', 'submit', false, array('onclick' => 'return confirmDelete()')); ?>
            </form>
        </div>
    </div>

    <script>
    function confirmDelete() {
        return confirm('Are you sure you want to delete ALL products? This action cannot be undone!');
    }
    </script>
    <?php

    // Handle import form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
        if (!wp_verify_nonce($_POST['erp_product_nonce'], 'erp_product_import')) {
            wp_die('Invalid nonce');
        }

        handle_file_import($_FILES['import_file']);
    }
}

// Handle CSV import
function handle_file_import($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        wp_die('Please upload a valid file.');
    }

    // Check file extension
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file_ext !== 'csv') {
        wp_die('Please upload a valid CSV file.');
    }

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        wp_die('Error opening file.');
    }

    // Determine delimiter based on file type
    $delimiter = ',';

    // Skip header row
    $headers = fgetcsv($handle, 0, $delimiter);

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (empty($data) || (count($data) === 1 && empty($data[0]))) {
            continue; // Skip empty rows
        }

        $product_data = array_combine($headers, $data);
        
        // Check if product exists
        $existing_product = get_posts(array(
            'post_type' => 'erp_product',
            'meta_key' => 'Product_PartNumber',
            'meta_value' => $product_data['Product_PartNumber'],
            'posts_per_page' => 1
        ));

        $post_data = array(
            'post_title' => $product_data['Product_PartNumber'],
            'post_type' => 'erp_product',
            'post_status' => 'publish'
        );

        if ($existing_product) {
            $post_data['ID'] = $existing_product[0]->ID;
            $post_id = wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
        }

        if ($post_id) {
            // Update meta fields
            update_post_meta($post_id, 'Product_Location_Id', sanitize_text_field($product_data['Product_Location_Id']));
            update_post_meta($post_id, 'Product_ProductLine_Id', sanitize_text_field($product_data['Product_ProductLine_Id']));
            update_post_meta($post_id, 'Product_PartNumber', sanitize_text_field($product_data['Product_PartNumber']));
            update_post_meta($post_id, 'Product_Description', sanitize_text_field($product_data['Product_Description']));
            update_post_meta($post_id, 'Product_Quantity', sanitize_text_field($product_data['Product_Quantity']));
            update_post_meta($post_id, 'Product_UOM', sanitize_text_field($product_data['Product_UOM']));
            update_post_meta($post_id, 'Product_NetPrice', sanitize_text_field($product_data['Product_NetPrice']));
        }
    }

    fclose($handle);
    echo '<div class="updated"><p>Import completed successfully!</p></div>';
}

// Add shortcode for frontend display
function erp_product_table_shortcode($atts) {
    ob_start();
    
    // Get all products
    $products = get_posts(array(
        'post_type' => 'erp_product',
        'posts_per_page' => -1
    ));

    // Get unique values for filters
    $locations = array();
    $product_lines = array();
    $part_numbers = array();

    foreach ($products as $product) {
        $locations[] = get_post_meta($product->ID, 'Product_Location_Id', true);
        $product_lines[] = get_post_meta($product->ID, 'Product_ProductLine_Id', true);
        $part_numbers[] = get_post_meta($product->ID, 'Product_PartNumber', true);
    }

    $locations = array_unique($locations);
    $product_lines = array_unique($product_lines);
    $part_numbers = array_unique($part_numbers);

    // Display search and filters
    ?>
    <div class="erp-filter-wrap">
        <div class="erp-filters">
            <div class="search-box">
            <input type="text" id="erp-search" placeholder="Search across all fields..." class="search-input">
        </div>
        
        <div class="filter-controls">
            <select id="location-filter">
                <option value="">All Locations</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?php echo esc_attr($location); ?>"><?php echo esc_html($location); ?></option>
                <?php endforeach; ?>
            </select>

            <select id="productline-filter">
                <option value="">All Product Lines</option>
                <?php foreach ($product_lines as $line): ?>
                    <option value="<?php echo esc_attr($line); ?>"><?php echo esc_html($line); ?></option>
                <?php endforeach; ?>
            </select>

            <select id="partnumber-filter">
                <option value="">All Part Numbers</option>
                <?php foreach ($part_numbers as $part): ?>
                    <option value="<?php echo esc_attr($part); ?>"><?php echo esc_html($part); ?></option>
                <?php endforeach; ?>
            </select>

            <button type="button" id="reset-filters" class="reset-button">Reset Filters</button>
        </div>
        <div id="no-results" class="no-results" style="display: none;">
            <p>No products found matching your criteria.</p>
        </div>
    </div>


    <div class="results-per-page">
        <label for="items-per-page">Show entries:</label>
        <select id="items-per-page">
            <option value="20">20</option>
            <option value="50">50</option>
            <option value="100" selected>100</option>
            <option value="250">250</option>
            <option value="500">500</option>
        </select>
    </div>

    <div class="table-container">
        <table class="erp-product-table">
            <thead>
                <tr>
                    <th>Location ID</th>
                    <th>Product Line ID</th>
                    <th>Part Number</th>
                    <th>Description</th>
                    <th>Quantity</th>
                    <th>UOM</th>
                    <th>Net Price</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?php echo esc_html(get_post_meta($product->ID, 'Product_Location_Id', true)); ?></td>
                        <td><?php echo esc_html(get_post_meta($product->ID, 'Product_ProductLine_Id', true)); ?></td>
                        <td><?php echo esc_html(get_post_meta($product->ID, 'Product_PartNumber', true)); ?></td>
                        <td><?php echo esc_html(get_post_meta($product->ID, 'Product_Description', true)); ?></td>
                        <td><?php echo esc_html(get_post_meta($product->ID, 'Product_Quantity', true)); ?></td>
                        <td><?php echo esc_html(get_post_meta($product->ID, 'Product_UOM', true)); ?></td>
                        <td><?php echo esc_html(get_post_meta($product->ID, 'Product_NetPrice', true)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination-container">
        <div class="pagination-info">
            Showing <span id="showing-start">1</span> to <span id="showing-end">100</span> of <span id="total-entries">0</span> entries
        </div>
        <div class="pagination-controls">
            <button id="prev-page" class="page-button" disabled>&laquo; Previous</button>
            <span id="page-numbers"></span>
            <button id="next-page" class="page-button">Next &raquo;</button>
        </div>
    </div>

     <script>
    document.addEventListener('DOMContentLoaded', function() {
        // State management
        let currentPage = 1;
        let itemsPerPage = 100;
        let filteredRows = [];
        let searchTimeout = null;

        // DOM elements
        const table = document.querySelector('.erp-product-table');
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const searchInput = document.getElementById('erp-search');
        const locationFilter = document.getElementById('location-filter');
        const productlineFilter = document.getElementById('productline-filter');
        const partnumberFilter = document.getElementById('partnumber-filter');
        const noResults = document.getElementById('no-results');
        const prevButton = document.getElementById('prev-page');
        const nextButton = document.getElementById('next-page');
        const pageNumbers = document.getElementById('page-numbers');
        const showingStart = document.getElementById('showing-start');
        const showingEnd = document.getElementById('showing-end');
        const totalEntries = document.getElementById('total-entries');
        const itemsPerPageSelect = document.getElementById('items-per-page');

        // Debounce function
        function debounce(func, wait) {
            return function executedFunction(...args) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => func.apply(this, args), wait);
            };
        }

        // Filter table based on search and filter criteria
        function filterTable() {
            const searchTerm = searchInput.value.toLowerCase();
            const location = locationFilter.value;
            const productLine = productlineFilter.value;
            const partNumber = partnumberFilter.value;

            filteredRows = rows.filter(row => {
                const cells = Array.from(row.getElementsByTagName('td'));
                const rowText = cells.map(cell => cell.textContent.toLowerCase()).join(' ');
                
                const matchesSearch = !searchTerm || rowText.includes(searchTerm);
                const matchesLocation = !location || cells[0].textContent === location;
                const matchesProductLine = !productLine || cells[1].textContent === productLine;
                const matchesPartNumber = !partNumber || cells[2].textContent === partNumber;

                return matchesSearch && matchesLocation && matchesProductLine && matchesPartNumber;
            });

            currentPage = 1;
            updatePagination();
            displayCurrentPage();
        }

        // Update pagination controls
        function updatePagination() {
            const totalPages = Math.ceil(filteredRows.length / itemsPerPage);
            pageNumbers.innerHTML = '';
            
            totalEntries.textContent = filteredRows.length;

            if (totalPages <= 1) {
                document.querySelector('.pagination-controls').style.display = 'none';
                return;
            }

            document.querySelector('.pagination-controls').style.display = 'flex';

            const startPage = Math.max(1, currentPage - 2);
            const endPage = Math.min(totalPages, startPage + 4);

            if (startPage > 1) {
                appendPageNumber(1);
                if (startPage > 2) appendEllipsis();
            }

            for (let i = startPage; i <= endPage; i++) {
                appendPageNumber(i);
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) appendEllipsis();
                appendPageNumber(totalPages);
            }

            prevButton.disabled = currentPage === 1;
            nextButton.disabled = currentPage === totalPages;
        }

        // Helper function to append page number
        function appendPageNumber(pageNum) {
            const span = document.createElement('span');
            span.className = `page-number${pageNum === currentPage ? ' current' : ''}`;
            span.textContent = pageNum;
            span.dataset.page = pageNum;
            pageNumbers.appendChild(span);
        }

        // Helper function to append ellipsis
        function appendEllipsis() {
            const span = document.createElement('span');
            span.className = 'page-ellipsis';
            span.textContent = '...';
            pageNumbers.appendChild(span);
        }

        // Display current page of results
        function displayCurrentPage() {
            const start = (currentPage - 1) * itemsPerPage;
            const end = Math.min(start + itemsPerPage, filteredRows.length);

            rows.forEach(row => row.style.display = 'none');
            
            filteredRows.slice(start, end).forEach(row => row.style.display = '');

            showingStart.textContent = filteredRows.length ? start + 1 : 0;
            showingEnd.textContent = end;
            noResults.style.display = filteredRows.length === 0 ? 'block' : 'none';
        }

        // Event listeners
        searchInput.addEventListener('input', debounce(filterTable, 300));
        locationFilter.addEventListener('change', filterTable);
        productlineFilter.addEventListener('change', filterTable);
        partnumberFilter.addEventListener('change', filterTable);

        prevButton.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                updatePagination();
                displayCurrentPage();
            }
        });

        nextButton.addEventListener('click', () => {
            const totalPages = Math.ceil(filteredRows.length / itemsPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                updatePagination();
                displayCurrentPage();
            }
        });

        pageNumbers.addEventListener('click', (e) => {
            if (e.target.classList.contains('page-number')) {
                currentPage = parseInt(e.target.dataset.page);
                updatePagination();
                displayCurrentPage();
            }
        });

        itemsPerPageSelect.addEventListener('change', function() {
            itemsPerPage = parseInt(this.value);
            currentPage = 1;
            updatePagination();
            displayCurrentPage();
        });

        // Add reset button functionality
        const resetButton = document.getElementById('reset-filters');
        resetButton.addEventListener('click', function() {
            // Reset search input
            searchInput.value = '';
            
            // Reset all select elements
            locationFilter.value = '';
            productlineFilter.value = '';
            partnumberFilter.value = '';
            
            // Trigger the filter to update the table
            filterTable();
        });

        // Initial setup
        filterTable();
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('erp_product_table', 'erp_product_table_shortcode');

// Update styles to include pagination
function erp_product_styles() {
    ?>
    <style>
        .search-box {
            margin-bottom: 20px;
        }
        .search-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 16px !important; /* Specifically for search input */
        }
        .erp-filters {
            margin-bottom: 20px;
        }
        .filter-controls input, 
        .filter-controls select {

            /* Prevent zoom */
            font-size: 14px !important;
            
            /* Remove iOS default styling */
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            
            /* Remove default border radius */
            border-radius: 3px;
            
            /* Prevent iOS text adjustment */
            -webkit-text-size-adjust: 100%;
            
            /* Disable iOS shadow */
            -webkit-box-shadow: none;
            box-shadow: none;

            background: none;
        }

        /* Add custom arrow using pseudo-element */
        .filter-controls select {
            background-image: linear-gradient(45deg, transparent 50%, #666 50%), 
                                linear-gradient(135deg, #666 50%, transparent 50%);
            background-position: calc(100% - 15px) 50%, 
                                 calc(100% - 10px) 50%;
            background-size: 5px 5px,  
                            5px 5px;
            background-repeat: no-repeat;
        }

        /* Remove default arrow in Firefox */
        .filter-controls select::-ms-expand {
            display: none;
        }

        /* Optional: Style the select on hover */
        .filter-controls select:hover {
            cursor: pointer;
        }

        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .filter-controls select {
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
            flex: 1;
            color: #666;
        }
        .erp-product-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            /* margin-top: 20px; */
        }
        .erp-product-table th,
        .erp-product-table td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .erp-product-table th {
            background-color: #f5f5f5;
            font-weight: bold;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 1px 0 #ddd;
        }
        .erp-product-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
		.pagination-container {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .pagination-info {
            color: #666;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .page-button {
            padding: 5px 10px;
            border: 1px solid #ddd;
            background: #f5f5f5;
            cursor: pointer;
            border-radius: 3px;
        }

        .page-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .page-button:hover:not(:disabled) {
            background: #e5e5e5;
        }

        .page-number {
            padding: 5px 10px;
            cursor: pointer;
            border: 1px solid #ddd;
            margin: 0 2px;
            border-radius: 3px;
        }

        .page-number.current {
            background: #a92f2d;
            color: white;
            border-color: #a92f2d;
        }

        .page-number:hover:not(.current) {
            background: #e5e5e5;
        }

        .page-ellipsis {
            padding: 5px 10px;
        }

        .table-container {
            overflow-x: auto;
            max-height: 80vh;
            position: relative;
        }

        @media (max-width: 768px) {

            .filter-controls {
                flex-direction: column;
            }

            .pagination-container {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .pagination-controls {
                justify-content: center;
            }
        }

        .results-per-page {
            margin-bottom: 15px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
        }

        .results-per-page select {
            padding: 5px;
            width: 60px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 14px !important;
            background: none;
            color: #666;
            
            /* Remove iOS default styling */
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            
            /* Remove default border radius */
            border-radius: 3px;
            
            /* Prevent iOS text adjustment */
            -webkit-text-size-adjust: 100%;
            
            /* Disable iOS shadow */
            -webkit-box-shadow: none;
            box-shadow: none;

            /* Add relative positioning for arrow */
            position: relative;
            
            /* Add padding for arrow space */
            padding-right: 30px !important;

            /* Remove default arrow in IE */
            background: none;
        }

        /* Add custom arrow using pseudo-element */
        .results-per-page select {
            background-image: linear-gradient(45deg, transparent 50%, #666 50%), 
                            linear-gradient(135deg, #666 50%, transparent 50%);
            background-position: calc(100% - 15px) 50%, 
                                calc(100% - 10px) 50%;
            background-size: 5px 5px, 
                            5px 5px;
            background-repeat: no-repeat;
        }

        /* Remove default arrow in Firefox */
        .results-per-page select::-ms-expand {
            display: none;
        }

        /* Optional: Style the select on hover */
        .results-per-page select:hover {
            cursor: pointer;
        }


        .erp-filter-wrap {
            position: sticky;
            top: 32px; /* Accounts for WordPress admin bar */
            background: white;
            padding: 15px;
            z-index: 100;
            border-bottom: 1px solid #ddd;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .no-results {
            padding-top: 20px;
            font-weight: bold;
            text-align: center;
        }

        /* Add padding to the body to prevent content jump */
        .table-container {
            /* padding-top: 15px; */
        }

        /* Adjust for non-admin users */
        .logged-out .erp-filter-wrap {
            top: 0;
        }

        .reset-button {
            padding: 5px 15px;
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #666;
        }

        .reset-button:hover {
            background: #e5e5e5;
        }

        .card {
            padding: 20px;
            background: #fff;
            border-left: 4px solid #2271b1;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin-bottom: 20px;
        }
        .delete.button {
            background: #dc3232;
            border-color: #dc3232;
            color: #fff;
        }
        .delete.button:hover {
            background: #c92c2c;
            border-color: #c92c2c;
            color: #fff;
        }
    </style>
    <?php
}
add_action('wp_head', 'erp_product_styles');

// Add Meta Box to ERP Product editor
function add_erp_product_meta_boxes() {
    add_meta_box(
        'erp_product_details',
        'Product Details',
        'render_erp_product_meta_box',
        'erp_product',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'add_erp_product_meta_boxes');

// Render Meta Box content
function render_erp_product_meta_box($post) {
    // Add nonce for security
    wp_nonce_field('erp_product_meta_box', 'erp_product_meta_box_nonce');

    // Get existing values
    $location_id = get_post_meta($post->ID, 'Product_Location_Id', true);
    $product_line_id = get_post_meta($post->ID, 'Product_ProductLine_Id', true);
    $part_number = get_post_meta($post->ID, 'Product_PartNumber', true);
    $description = get_post_meta($post->ID, 'Product_Description', true);
    $quantity = get_post_meta($post->ID, 'Product_Quantity', true);
    $uom = get_post_meta($post->ID, 'Product_UOM', true);
    $net_price = get_post_meta($post->ID, 'Product_NetPrice', true);
    ?>
    <table class="form-table">
        <tr>
            <th><label for="location_id">Location ID</label></th>
            <td><input type="text" id="location_id" name="location_id" value="<?php echo esc_attr($location_id); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="product_line_id">Product Line ID</label></th>
            <td><input type="text" id="product_line_id" name="product_line_id" value="<?php echo esc_attr($product_line_id); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="part_number">Part Number</label></th>
            <td><input type="text" id="part_number" name="part_number" value="<?php echo esc_attr($part_number); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="description">Description</label></th>
            <td><textarea id="description" name="description" rows="3" class="large-text"><?php echo esc_textarea($description); ?></textarea></td>
        </tr>
        <tr>
            <th><label for="quantity">Quantity</label></th>
            <td><input type="number" id="quantity" name="quantity" value="<?php echo esc_attr($quantity); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="uom">UOM</label></th>
            <td><input type="text" id="uom" name="uom" value="<?php echo esc_attr($uom); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="net_price">Net Price</label></th>
            <td><input type="number" id="net_price" name="net_price" value="<?php echo esc_attr($net_price); ?>" class="regular-text" step="0.01"></td>
        </tr>
    </table>
    <?php
}

// Save Meta Box data
function save_erp_product_meta_box($post_id) {
    // Check if nonce is set
    if (!isset($_POST['erp_product_meta_box_nonce'])) {
        return;
    }

    // Verify nonce
    if (!wp_verify_nonce($_POST['erp_product_meta_box_nonce'], 'erp_product_meta_box')) {
        return;
    }

    // If this is an autosave, don't do anything
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check user permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save fields
    $fields = array(
        'location_id' => 'Product_Location_Id',
        'product_line_id' => 'Product_ProductLine_Id',
        'part_number' => 'Product_PartNumber',
        'description' => 'Product_Description',
        'quantity' => 'Product_Quantity',
        'uom' => 'Product_UOM',
        'net_price' => 'Product_NetPrice'
    );

    foreach ($fields as $field => $meta_key) {
        if (isset($_POST[$field])) {
            update_post_meta(
                $post_id,
                $meta_key,
                sanitize_text_field($_POST[$field])
            );
        }
    }
}
add_action('save_post_erp_product', 'save_erp_product_meta_box');

// Add columns to admin list view
function add_erp_product_columns($columns) {
    $new_columns = array();
    $new_columns['cb'] = $columns['cb'];
    $new_columns['title'] = $columns['title'];
    $new_columns['location_id'] = 'Location ID';
    $new_columns['product_line'] = 'Product Line';
    $new_columns['part_number'] = 'Part Number';
    $new_columns['quantity'] = 'Quantity';
    $new_columns['price'] = 'Price';
    return $new_columns;
}
add_filter('manage_erp_product_posts_columns', 'add_erp_product_columns');

// Fill custom columns in admin list view
function fill_erp_product_columns($column, $post_id) {
    switch ($column) {
        case 'location_id':
            echo esc_html(get_post_meta($post_id, 'Product_Location_Id', true));
            break;
        case 'product_line':
            echo esc_html(get_post_meta($post_id, 'Product_ProductLine_Id', true));
            break;
        case 'part_number':
            echo esc_html(get_post_meta($post_id, 'Product_PartNumber', true));
            break;
        case 'quantity':
            echo esc_html(get_post_meta($post_id, 'Product_Quantity', true));
            break;
        case 'price':
            echo '$' . esc_html(get_post_meta($post_id, 'Product_NetPrice', true));
            break;
    }
}
add_action('manage_erp_product_posts_custom_column', 'fill_erp_product_columns', 10, 2);

// Make columns sortable
function make_erp_product_columns_sortable($columns) {
    $columns['location_id'] = 'Product_Location_Id';
    $columns['product_line'] = 'Product_ProductLine_Id';
    $columns['part_number'] = 'Product_PartNumber';
    $columns['quantity'] = 'Product_Quantity';
    $columns['price'] = 'Product_NetPrice';
    return $columns;
}
add_filter('manage_edit-erp_product_sortable_columns', 'make_erp_product_columns_sortable');

// Add function to delete all products
function delete_all_erp_products() {
    $args = array(
        'post_type' => 'erp_product',
        'posts_per_page' => -1,
        'post_status' => 'any',
        'fields' => 'ids',
    );

    $products = get_posts($args);

    if (!empty($products)) {
        foreach ($products as $product_id) {
            wp_delete_post($product_id, true); // true = force delete, bypass trash
        }
        return true;
    }

    return false;
}