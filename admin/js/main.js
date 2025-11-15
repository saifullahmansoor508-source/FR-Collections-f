// =============================================================================
// BULK UPLOAD HANDLER - Main JavaScript File
// =============================================================================
// This file handles the bulk product upload functionality including:
// - Excel file upload and parsing
// - Sheet mapping and validation
// - Image upload and assignment
// - Data preview and database saving
// =============================================================================

// =============================================================================
// GLOBAL VARIABLES
// =============================================================================
let currentStep = 1;
let uploadedSheetData = {};
let sheetHeaders = {};
let columnMappings = {};
let mergedProducts = [];
let uploadedImages = [];

// =============================================================================
// DATABASE FIELD DEFINITIONS
// =============================================================================
const dbFields = {
    products: [
        'product_no', 'name', 'description', 'category', 'original_price', 'discounted_price',
        'commission', 'delivery_charges', 'stock', 'sold', 'status', 'display_location', 'keywords'
    ],
    features: [
        'product_no', 'feature_name', 'feature_description'
    ],
    variants: [
        'product_no', 'variant_type', 'variant_name', 'sale_price', 'original_price', 'image_url'
    ],
    reviews: [
        'product_no', 'reviewer_name', 'rating', 'review_text'
    ],
    soldinfo: [
        'product_no', 'units_sold', 'views', 'stock_count'
    ]
};

// =============================================================================
// FIELD CONFIGURATIONS FOR EACH SHEET TYPE
// =============================================================================
window.sheetFieldConfigs = {
    products: {
        name: 'Products',
        icon: '📦',
        color: '#007bff',
        fields: [
            { key: 'product_no', label: 'Product No', required: true },
            { key: 'name', label: 'Name', required: true },
            { key: 'description', label: 'Description', required: false },
            { key: 'category', label: 'Category', required: false },
            { key: 'original_price', label: 'Original Price', required: true },
            { key: 'discounted_price', label: 'Discounted Price', required: false },
            { key: 'commission', label: 'Commission', required: false },
            { key: 'delivery_charges', label: 'Delivery Charges', required: false },
            { key: 'stock', label: 'Stock', required: false },
            { key: 'sold', label: 'Sold', required: false },
            { key: 'status', label: 'Status', required: false },
            { key: 'display_location', label: 'Display Location', required: false },
            { key: 'keywords', label: 'Keywords', required: false }
        ]
    },
    features: {
        name: 'Features',
        icon: '⭐',
        color: '#28a745',
        fields: [
            { key: 'product_no', label: 'Product No', required: true },
            { key: 'feature_name', label: 'Feature Name', required: true },
            { key: 'feature_description', label: 'Feature Description', required: false }
        ]
    },
    variants: {
        name: 'Variants',
        icon: '🎨',
        color: '#fd7e14',
        fields: [
            { key: 'product_no', label: 'Product No', required: true },
            { key: 'variant_type', label: 'Variant Type', required: false },
            { key: 'variant_name', label: 'Variant Name', required: true },
            { key: 'sale_price', label: 'Sale Price', required: false },
            { key: 'original_price', label: 'Original Price', required: false },
            { key: 'image_url', label: 'Image URL', required: false }
        ]
    },
    reviews: {
        name: 'Reviews',
        icon: '💬',
        color: '#6f42c1',
        fields: [
            { key: 'product_no', label: 'Product No', required: true },
            { key: 'reviewer_name', label: 'Reviewer Name', required: true },
            { key: 'rating', label: 'Rating', required: false },
            { key: 'review_text', label: 'Review Text', required: true }
        ]
    },
    soldinfo: {
        name: 'Sold Info',
        icon: '📊',
        color: '#6c757d',
        fields: [
            { key: 'product_no', label: 'Product No', required: true },
            { key: 'units_sold', label: 'Units Sold', required: false },
            { key: 'views', label: 'Views', required: false },
            { key: 'stock_count', label: 'Stock Count', required: false }
        ]
    }
};

// =============================================================================
// AUTO-MAP KEYWORDS FOR BETTER DETECTION
// =============================================================================
window.autoMapKeywords = {
    'product_no': ['product no', 'product_id', 'id', 'product number', 'no', 'code'],
    'name': ['name', 'product name', 'title', 'product title'],
    'description': ['description', 'desc', 'details', 'info'],
    'category': ['category', 'category name', 'type', 'group'],
    'original_price': ['original price', 'price', 'mrp', 'cost', 'base price'],
    'discounted_price': ['discounted price', 'discount price', 'sale price', 'selling price'],
    'commission': ['commission', 'commission rate', 'affiliate'],
    'delivery_charges': ['delivery charges', 'delivery', 'shipping', 'delivery cost'],
    'stock': ['stock', 'stock count', 'quantity', 'qty'],
    'sold': ['sold', 'units sold', 'sales', 'sales count'],
    'status': ['status', 'product status', 'state'],
    'display_location': ['display location', 'location', 'display', 'show'],
    'keywords': ['keywords', 'tags', 'search keywords'],
    'feature_name': ['feature name', 'name', 'feature', 'spec name'],
    'feature_description': ['feature description', 'description', 'value', 'spec value'],
    'variant_type': ['variant type', 'type', 'variant category'],
    'variant_name': ['variant name', 'name', 'option', 'variant'],
    'sale_price': ['sale price', 'price', 'variant price'],
    'image_url': ['image url', 'image', 'picture', 'photo'],
    'reviewer_name': ['reviewer name', 'name', 'reviewer', 'customer'],
    'rating': ['rating', 'stars', 'score'],
    'review_text': ['review text', 'comment', 'review', 'feedback'],
    'units_sold': ['units sold', 'sold', 'sales', 'quantity sold'],
    'views': ['views', 'impressions', 'page views'],
    'stock_count': ['stock count', 'current stock', 'available']
};

// =============================================================================
// INITIALIZATION
// =============================================================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('Bulk upload handler initialized with 5 steps');
    initializeHandlers();
});

// =============================================================================
// INITIALIZE EVENT HANDLERS
// =============================================================================
function initializeHandlers() {
    // Upload type toggle
    document.querySelectorAll('input[name="uploadType"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.getElementById('excel-upload-section').style.display = this.value === 'excel' ? 'block' : 'none';
            document.getElementById('gsheet-upload-section').style.display = this.value === 'gsheet' ? 'block' : 'none';
        });
    });

    // Excel upload handlers
    const dropZone = document.getElementById('dropZone');
    const excelFile = document.getElementById('excelFile');
    if (dropZone && excelFile) {
        dropZone.onclick = () => excelFile.click();
        dropZone.ondragover = (e) => { e.preventDefault(); dropZone.classList.add('dragover'); };
        dropZone.ondragleave = () => dropZone.classList.remove('dragover');
        dropZone.ondrop = (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            if (e.dataTransfer.files[0]) handleExcelUpload(e.dataTransfer.files[0]);
        };
        excelFile.onchange = (e) => { if (e.target.files[0]) handleExcelUpload(e.target.files[0]); };
    }

    // Image upload handlers
    const imageDropZone = document.getElementById('imageDropZone');
    const bulkImages = document.getElementById('bulkImages');
    if (imageDropZone && bulkImages) {
        imageDropZone.onclick = () => bulkImages.click();
        imageDropZone.ondragover = (e) => { e.preventDefault(); imageDropZone.classList.add('dragover'); };
        imageDropZone.ondragleave = () => imageDropZone.classList.remove('dragover');
        imageDropZone.ondrop = (e) => {
            e.preventDefault();
            imageDropZone.classList.remove('dragover');
            if (e.dataTransfer.files.length) handleBulkImageUpload(e.dataTransfer.files);
        };
        bulkImages.onchange = (e) => { if (e.target.files.length) handleBulkImageUpload(e.target.files); };
    }
}

// =============================================================================
// EXCEL FILE UPLOAD HANDLER
// =============================================================================
function handleExcelUpload(file) {
    const progressDiv = document.getElementById('uploadProgress');
    const progressBar = document.getElementById('uploadProgressBar');
    const statusDiv = document.getElementById('progressStatus');
    
    progressDiv.style.display = 'block';
    progressBar.style.width = '30%';
    statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Reading Excel file...';
    
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            progressBar.style.width = '60%';
            statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Parsing sheets...';
            
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, {type: 'array'});
            
            // Clear previous data
            uploadedSheetData = {};
            sheetHeaders = {};
            columnMappings = {};
            
            // Process each sheet
            workbook.SheetNames.forEach((sheetName, index) => {
                const sheet = workbook.Sheets[sheetName];
                const jsonData = XLSX.utils.sheet_to_json(sheet, { header: 1 });
                
                if (jsonData.length < 2) return;
                
                const headers = jsonData[0];
                const rows = jsonData.slice(1);
                
                // Determine sheet type
                const sheetType = detectSheetType(sheetName, index);
                
                uploadedSheetData[sheetType] = rows;
                sheetHeaders[sheetType] = headers;
                columnMappings[sheetType] = {};
            });
            
            progressBar.style.width = '100%';
            statusDiv.innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>Successfully parsed sheets!';
            
            setTimeout(() => {
                progressDiv.style.display = 'none';
                goToStep(2);
                generateMappingInterface();
            }, 1000);
            
        } catch (error) {
            progressBar.style.width = '100%';
            progressBar.classList.add('bg-danger');
            statusDiv.innerHTML = `<i class="fas fa-times-circle text-danger me-2"></i>Error: ${error.message}`;
        }
    };
    
    reader.readAsArrayBuffer(file);
}

// =============================================================================
// DETECT SHEET TYPE FROM NAME
// =============================================================================
function detectSheetType(sheetName, index) {
    const lowerName = sheetName.toLowerCase().trim();
    
    if (lowerName.includes('product') && !lowerName.includes('sold')) return 'products';
    if (lowerName.includes('feature')) return 'features';
    if (lowerName.includes('variant')) return 'variants';
    if (lowerName.includes('review')) return 'reviews';
    if (lowerName.includes('sold') || lowerName.includes('info') || lowerName.includes('sale')) return 'soldinfo';
    
    // Fallback to index
    const types = ['products', 'features', 'variants', 'reviews', 'soldinfo'];
    return types[index] || 'products';
}

// =============================================================================
// GENERATE MAPPING INTERFACE
// =============================================================================
function generateMappingInterface() {
    const container = document.getElementById('mappingContainer');
    const navigation = document.getElementById('mappingNavigation');
    
    if (Object.keys(sheetHeaders).length === 0) {
        container.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Error:</strong> No sheets found. Please upload a valid Excel file with at least one sheet.
            </div>
        `;
        return;
    }
    
    let html = '';
    
    // Generate mapping section for each sheet
    Object.keys(sheetHeaders).forEach((sheetKey, index) => {
        const headers = sheetHeaders[sheetKey];
        const config = window.sheetFieldConfigs[sheetKey];
        
        if (!config) return; // Skip if no config for this sheet type
        
        // Auto-map columns using the config.fields
        const autoMappings = autoMapColumns(headers, config.fields);
        columnMappings[sheetKey] = autoMappings;
        
        html += renderSheetMappingCard(sheetKey, headers, config, autoMappings, index === 0);
    });
    
    container.innerHTML = html;
    navigation.style.display = 'flex';
}

// =============================================================================
// RENDER SHEET MAPPING CARD
// =============================================================================
function renderSheetMappingCard(sheetType, headers, config, mappings, isExpanded) {
    const rowCount = uploadedSheetData[sheetType] ? uploadedSheetData[sheetType].length : 0;
    
    let html = `
        <div class="card mb-3" style="border-left: 4px solid ${config.color};">
            <div class="card-header" style="background-color: ${config.color}15; cursor: pointer;" 
                 onclick="toggleSheetMapping('${sheetType}')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">
                            <span style="font-size: 1.3em; margin-right: 10px;">${config.icon}</span>
                            <strong>${config.name}</strong>
                            <span class="badge bg-secondary ms-2">${headers.length} columns</span>
                            <span class="badge bg-info ms-1">${rowCount} rows</span>
                        </h6>
                    </div>
                    <i class="fas fa-chevron-down" id="chevron-${sheetType}"></i>
                </div>
            </div>
            <div class="collapse ${isExpanded ? 'show' : ''}" id="collapse-${sheetType}">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th width="25%">Database Field</th>
                                    <th width="25%">Auto-Detected Column</th>
                                    <th width="35%">Manual Selection</th>
                                    <th width="15%" class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
    `;
    
    config.fields.forEach(field => {
        const mappedColumn = mappings[field.key];
        const isMapped = mappedColumn !== null;
        
        html += `
            <tr>
                <td>
                    <strong>${field.label}</strong>
                    ${field.required ? '<span class="text-danger">*</span>' : ''}
                    <br><small class="text-muted">${field.key}</small>
                </td>
                <td>
                    ${isMapped ? 
                        `<span class="badge bg-success">${mappedColumn}</span>` : 
                        '<span class="text-muted">Not detected</span>'}
                </td>
                <td>
                    <select class="form-select form-select-sm" 
                            onchange="updateMapping('${sheetType}', '${field.key}', this.value)">
                        <option value="">-- Select Column --</option>
                        ${headers.map(h => 
                            `<option value="${h}" ${h === mappedColumn ? 'selected' : ''}>${h}</option>`
                        ).join('')}
                    </select>
                </td>
                <td class="text-center">
                    ${isMapped ? 
                        '<i class="fas fa-check-circle text-success" title="Mapped"></i>' : 
                        (field.required ? 
                            '<i class="fas fa-exclamation-circle text-danger" title="Required"></i>' : 
                            '<i class="fas fa-minus-circle text-secondary" title="Optional"></i>')}
                </td>
            </tr>
        `;
    });
    
    html += `
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Data Preview -->
                    <div class="mt-3">
                        <h6 class="mb-2"><i class="fas fa-eye me-2"></i>Data Preview (First 3 Rows)</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        ${headers.map(h => `<th style="font-size: 11px;">${h}</th>`).join('')}
                                    </tr>
                                </thead>
                                <tbody>
                                    ${uploadedSheetData[sheetType].slice(0, 3).map(row => `
                                        <tr>
                                            ${headers.map((h, idx) => `<td style="font-size: 11px;">${row[idx] || ''}</td>`).join('')}
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    return html;
}

// =============================================================================
// AUTO-MAP COLUMNS BASED ON KEYWORDS
// =============================================================================
function autoMapColumns(headers, fields) {
    const mappings = {};
    
    fields.forEach(field => {
        const keywords = window.autoMapKeywords[field.key] || [];
        let matched = null;
        
        for (const header of headers) {
            const h = String(header).toLowerCase().trim();
            
            if (keywords.some(keyword => {
                const k = keyword.toLowerCase();
                return h === k || h.includes(k) || k.includes(h);
            })) {
                matched = header;
                break;
            }
        }
        
        mappings[field.key] = matched;
    });
    
    return mappings;
}

// =============================================================================
// TOGGLE SHEET MAPPING ACCORDION
// =============================================================================
function toggleSheetMapping(sheetType) {
    const collapse = document.getElementById(`collapse-${sheetType}`);
    const chevron = document.getElementById(`chevron-${sheetType}`);
    
    if (collapse.classList.contains('show')) {
        collapse.classList.remove('show');
        chevron.classList.remove('fa-chevron-up');
        chevron.classList.add('fa-chevron-down');
    } else {
        collapse.classList.add('show');
        chevron.classList.remove('fa-chevron-down');
        chevron.classList.add('fa-chevron-up');
    }
}

// =============================================================================
// UPDATE COLUMN MAPPING
// =============================================================================
function updateMapping(sheetType, fieldKey, columnName) {
    if (!columnMappings[sheetType]) {
        columnMappings[sheetType] = {};
    }
    
    columnMappings[sheetType][fieldKey] = columnName || null;
    
    console.log('Updated mapping:', sheetType, fieldKey, columnName);
}

// =============================================================================
// SAVE MAPPING AND VALIDATE
// =============================================================================
function saveMapping(e) {
    // Prefer button with known id, else attempt to derive from event
    const btn = document.getElementById('saveMappingBtn') || (e && e.target) || null;
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
    }
    
    // Validate required fields
    let hasErrors = false;
    const errors = [];
    
    Object.keys(columnMappings).forEach(sheetType => {
        // Normalize key to match sheetFieldConfigs
        let normalizedKey = String(sheetType).toLowerCase().replace(/\s+/g, '').replace(/[^a-z0-9]/g, '');
        const fallbackTypes = ['products', 'features', 'variants', 'reviews', 'soldinfo'];
        
        // Get config using same logic as generateMappingInterface
        let config = window.sheetFieldConfigs ? window.sheetFieldConfigs[normalizedKey] : null;
        if (!config && window.sheetFieldConfigs) {
            config = window.sheetFieldConfigs[String(sheetType).toLowerCase()];
        }
        if (!config && window.sheetFieldConfigs) {
            // Try index-based fallback
            const sheetIndex = Object.keys(columnMappings).indexOf(sheetType);
            if (sheetIndex >= 0 && sheetIndex < fallbackTypes.length) {
                config = window.sheetFieldConfigs[fallbackTypes[sheetIndex]];
            }
        }
        
        if (!config) {
            console.warn('No config found for sheet type:', sheetType, 'normalized:', normalizedKey);
            return;
        }
        
        const mappings = columnMappings[sheetType];
        
        config.fields.forEach(field => {
            if (field.required && !mappings[field.key]) {
                hasErrors = true;
                errors.push(`${config.name}: ${field.label} is required`);
            }
        });
    });
    
    if (hasErrors) {
        alert('Please map all required fields:\n\n' + errors.join('\n'));
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = 'Save Mapping & Continue <i class="fas fa-arrow-right ms-2"></i>';
        }
        return;
    }
    
    // Merge data by Product No
    setTimeout(() => {
        mergeDataByProductNo();
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = 'Save Mapping & Continue <i class="fas fa-arrow-right ms-2"></i>';
        }
        goToStep(3);
    }, 1000);
}

// Expose saveMappingSafe as fallback name if original saveMapping is called
if (typeof window.saveMapping === 'undefined') {
    window.saveMapping = saveMapping;
}

// =============================================================================
// MERGE DATA BY PRODUCT NUMBER
// =============================================================================
function mergeDataByProductNo() {
    const productsMap = new Map();
    
    // Process products sheet
    if (uploadedSheetData.products) {
        const headers = sheetHeaders.products;
        const mappings = columnMappings.products;
        
        uploadedSheetData.products.forEach(row => {
            const productNo = getMappedValue(row, headers, mappings, 'product_no');
            if (!productNo) return;
            
            productsMap.set(productNo, {
                product_no: productNo,
                product_name: getMappedValue(row, headers, mappings, 'name'),
                description: getMappedValue(row, headers, mappings, 'description'),
                original_price: getMappedValue(row, headers, mappings, 'original_price'),
                discount_price: getMappedValue(row, headers, mappings, 'discounted_price'),
                category_name: getMappedValue(row, headers, mappings, 'category'),
                stock_count: getMappedValue(row, headers, mappings, 'stock') || 0,
                features: [],
                variants: [],
                reviews: [],
                soldInfo: {},
                images: []
            });
        });
    }
    
    // Add features
    if (uploadedSheetData.features) {
        const headers = sheetHeaders.features;
        const mappings = columnMappings.features;
        
        uploadedSheetData.features.forEach(row => {
            const productNo = getMappedValue(row, headers, mappings, 'product_no');
            if (productsMap.has(productNo)) {
                productsMap.get(productNo).features.push({
                    name: getMappedValue(row, headers, mappings, 'feature_name'),
                    description: getMappedValue(row, headers, mappings, 'feature_description')
                });
            }
        });
    }
    
    // Add variants
    if (uploadedSheetData.variants) {
        const headers = sheetHeaders.variants;
        const mappings = columnMappings.variants;
        
        uploadedSheetData.variants.forEach(row => {
            const productNo = getMappedValue(row, headers, mappings, 'product_no');
            if (productsMap.has(productNo)) {
                productsMap.get(productNo).variants.push({
                    type: getMappedValue(row, headers, mappings, 'variant_type') || 'Color',
                    name: getMappedValue(row, headers, mappings, 'variant_name'),
                    price: getMappedValue(row, headers, mappings, 'sale_price')
                });
            }
        });
    }
    
    // Add reviews
    if (uploadedSheetData.reviews) {
        const headers = sheetHeaders.reviews;
        const mappings = columnMappings.reviews;
        
        uploadedSheetData.reviews.forEach(row => {
            const productNo = getMappedValue(row, headers, mappings, 'product_no');
            if (productsMap.has(productNo)) {
                productsMap.get(productNo).reviews.push({
                    reviewer: getMappedValue(row, headers, mappings, 'reviewer_name'),
                    rating: getMappedValue(row, headers, mappings, 'rating'),
                    comment: getMappedValue(row, headers, mappings, 'review_text')
                });
            }
        });
    }
    
    // Add sold info
    if (uploadedSheetData.soldinfo) {
        const headers = sheetHeaders.soldinfo;
        const mappings = columnMappings.soldinfo;
        
        uploadedSheetData.soldinfo.forEach(row => {
            const productNo = getMappedValue(row, headers, mappings, 'product_no');
            if (productsMap.has(productNo)) {
                productsMap.get(productNo).soldInfo = {
                    units_sold: getMappedValue(row, headers, mappings, 'units_sold') || 0,
                    views: getMappedValue(row, headers, mappings, 'views') || 0
                };
            }
        });
    }
    
    mergedProducts = Array.from(productsMap.values());
    console.log('Merged products:', mergedProducts);
}

// =============================================================================
// GET MAPPED VALUE FROM ROW
// =============================================================================
function getMappedValue(row, headers, mappings, fieldKey) {
    const columnName = mappings[fieldKey];
    if (!columnName) return null;
    
    const columnIndex = headers.indexOf(columnName);
    if (columnIndex === -1) return null;
    
    return row[columnIndex];
}

// =============================================================================
// HANDLE BULK IMAGE UPLOAD
// =============================================================================
function handleBulkImageUpload(files) {
    const processing = document.getElementById('imageProcessing');
    const progressBar = document.getElementById('imageProgressBar');
    const status = document.getElementById('imageStatus');
    
    processing.style.display = 'block';
    uploadedImages = [];
    
    let processed = 0;
    const total = files.length;
    
    Array.from(files).forEach(file => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const imageData = {
                name: file.name,
                data: e.target.result,
                file: file
            };
            
            // Auto-map based on filename
            const mapping = autoMapImageFilename(file.name);
            if (mapping) {
                imageData.productNo = mapping.productNo;
                imageData.type = mapping.type;
                imageData.variantIndex = mapping.variantIndex;
            }
            
            uploadedImages.push(imageData);
            processed++;
            
            const percent = Math.round((processed / total) * 100);
            progressBar.style.width = percent + '%';
            progressBar.textContent = percent + '%';
            status.innerHTML = `<p>Processed ${processed} of ${total} images...</p>`;
            
            if (processed === total) {
                displayImageResults();
            }
        };
        reader.readAsDataURL(file);
    });
}

// =============================================================================
// AUTO-MAP IMAGE BY FILENAME
// =============================================================================
function autoMapImageFilename(filename) {
    const nameWithoutExt = filename.replace(/\.(jpg|jpeg|png)$/i, '');
    
    // Primary image: ProductNo.jpg
    const primaryMatch = nameWithoutExt.match(/^(\d+)$/);
    if (primaryMatch) {
        return { productNo: primaryMatch[1], type: 'primary', variantIndex: null };
    }
    
    // Variant image: ProductNo(1).jpg
    const variantMatch = nameWithoutExt.match(/^(\d+)\((\d+)\)$/);
    if (variantMatch) {
        return { productNo: variantMatch[1], type: 'variant', variantIndex: parseInt(variantMatch[2]) - 1 };
    }
    
    return null;
}

// =============================================================================
// DISPLAY IMAGE RESULTS
// =============================================================================
function displayImageResults() {
    document.getElementById('imageProcessing').style.display = 'none';
    
    const autoMapped = uploadedImages.filter(img => img.productNo);
    const unmapped = uploadedImages.filter(img => !img.productNo);
    
    // Show auto-mapped
    if (autoMapped.length > 0) {
        const section = document.getElementById('autoMappedSection');
        const grid = document.getElementById('autoMappedGrid');
        section.style.display = 'block';
        grid.innerHTML = '';
        
        autoMapped.forEach(img => {
            const col = document.createElement('div');
            col.className = 'col-md-2 col-sm-4';
            col.innerHTML = `
                <div class="image-preview-card ${img.type}">
                    <img src="${img.data}" alt="${img.name}">
                    <small class="d-block mt-2 text-truncate">${img.name}</small>
                    <span class="badge bg-${img.type === 'primary' ? 'primary' : 'success'} mt-1">
                        ${img.type === 'primary' ? 'Primary' : 'Variant ' + (img.variantIndex + 1)}
                    </span>
                </div>
            `;
            grid.appendChild(col);
        });
    }
    
    // Show unmapped
    if (unmapped.length > 0) {
        const section = document.getElementById('unmappedSection');
        const tbody = document.getElementById('unmappedImagesBody');
        section.style.display = 'block';
        tbody.innerHTML = '';
        
        unmapped.forEach((img, idx) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><img src="${img.data}" class="product-thumb"></td>
                <td>${img.name}</td>
                <td>
                    <select class="form-select" id="unmapped_product_${idx}">
                        <option value="">-- Select Product --</option>
                        ${mergedProducts.map(p => 
                            `<option value="${p.product_no}">${p.product_no} - ${p.product_name}</option>`
                        ).join('')}
                    </select>
                </td>
                <td>
                    <select class="form-select" id="unmapped_type_${idx}">
                        <option value="primary">Primary Image</option>
                        <option value="variant_0">Variant 1</option>
                        <option value="variant_1">Variant 2</option>
                        <option value="variant_2">Variant 3</option>
                    </select>
                </td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="assignUnmappedImage(${idx})">
                        <i class="fas fa-check"></i> Assign
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }
    
    // Show summary
    document.getElementById('imageSummary').style.display = 'block';
    document.getElementById('autoMatchedCount').textContent = autoMapped.length;
    document.getElementById('manualAssignedCount').textContent = 0;
    document.getElementById('skippedCount').textContent = unmapped.length;
    
    // Enable continue button
    document.getElementById('continueToPreview').disabled = false;
}

// =============================================================================
// ASSIGN UNMAPPED IMAGE
// =============================================================================
function assignUnmappedImage(idx) {
    const productNo = document.getElementById(`unmapped_product_${idx}`).value;
    const typeValue = document.getElementById(`unmapped_type_${idx}`).value;
    
    if (!productNo) {
        alert('Please select a product');
        return;
    }
    
    const unmapped = uploadedImages.filter(img => !img.productNo);
    const img = unmapped[idx];
    
    img.productNo = productNo;
    if (typeValue === 'primary') {
        img.type = 'primary';
        img.variantIndex = null;
    } else {
        img.type = 'variant';
        img.variantIndex = parseInt(typeValue.split('_')[1]);
    }
    
    // Update counts
    const manualCount = parseInt(document.getElementById('manualAssignedCount').textContent);
    const skippedCount = parseInt(document.getElementById('skippedCount').textContent);
    document.getElementById('manualAssignedCount').textContent = manualCount + 1;
    document.getElementById('skippedCount').textContent = skippedCount - 1;
    
    // Remove row
    event.target.closest('tr').remove();
    
    if (document.getElementById('unmappedImagesBody').children.length === 0) {
        document.getElementById('unmappedSection').style.display = 'none';
    }
}

// =============================================================================
// NAVIGATE TO STEP
// =============================================================================
function goToStep(step) {
    document.querySelectorAll('.step-item').forEach(item => {
        const itemStep = parseInt(item.dataset.step);
        item.classList.remove('active', 'completed');
        if (itemStep < step) item.classList.add('completed');
    });
    document.querySelector(`.step-item[data-step="${step}"]`).classList.add('active');
    
    document.querySelectorAll('.step-content').forEach(content => content.classList.remove('active'));
    document.getElementById(`step-${step}`).classList.add('active');
    
    currentStep = step;
    if (step === 4) generatePreviewTable();
    if (step === 5) startSavingProducts();
    window.scrollTo(0, 0);
}

// =============================================================================
// GENERATE PREVIEW TABLE
// =============================================================================
function generatePreviewTable() {
    const container = document.getElementById('previewTableBody').parentElement;
    container.innerHTML = `
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Review the data for each sheet below. Each sheet shows only its relevant fields (columns) from the uploaded data.
        </div>
        <ul class="nav nav-tabs" id="sheetTabs" role="tablist">
            ${Object.keys(sheetHeaders).map((sheetKey, index) => `
                <li class="nav-item" role="presentation">
                    <button class="nav-link ${index === 0 ? 'active' : ''}" id="${sheetKey}-tab" data-bs-toggle="tab" data-bs-target="#${sheetKey}-pane" type="button" role="tab" aria-controls="${sheetKey}-pane" aria-selected="${index === 0 ? 'true' : 'false'}">
                        ${sheetKey.charAt(0).toUpperCase() + sheetKey.slice(1)} (${uploadedSheetData[sheetKey] ? uploadedSheetData[sheetKey].length : 0} rows)
                    </button>
                </li>
            `).join('')}
        </ul>
        <div class="tab-content mt-3" id="sheetTabContent">
            ${Object.keys(sheetHeaders).map((sheetKey, index) => {
                const headers = sheetHeaders[sheetKey];
                const rows = uploadedSheetData[sheetKey] || [];
                return `
                    <div class="tab-pane fade ${index === 0 ? 'show active' : ''}" id="${sheetKey}-pane" role="tabpanel" aria-labelledby="${sheetKey}-tab">
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        ${headers.map(h => `<th style="font-size: 11px;">${h}</th>`).join('')}
                                    </tr>
                                </thead>
                                <tbody>
                                    ${rows.slice(0, 10).map(row => `
                                        <tr>
                                            ${headers.map((h, idx) => `<td style="font-size: 11px;">${row[idx] || ''}</td>`).join('')}
                                        </tr>
                                    `).join('')}
                                    ${rows.length > 10 ? `<tr><td colspan="${headers.length}" class="text-center text-muted">... and ${rows.length - 10} more rows</td></tr>` : ''}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;
}

// =============================================================================
// START SAVING PRODUCTS
// =============================================================================
function startSavingProducts() {
    const progressBar = document.getElementById('saveProgressBar');
    const status = document.getElementById('saveStatus');
    
    let saved = 0;
    const total = mergedProducts.length;
    
    status.textContent = `Saving products: ${saved}/${total}`;
    
    // Simulate progress
    const interval = setInterval(() => {
        saved++;
        const percent = Math.round((saved / total) * 100);
        progressBar.style.width = percent + '%';
        progressBar.textContent = percent + '%';
        status.textContent = `Saving products: ${saved}/${total}`;
        
        if (saved >= total) {
            clearInterval(interval);
            saveToDatabase();
        }
    }, 50);
}

// =============================================================================
// SAVE TO DATABASE
// =============================================================================
async function saveToDatabase() {
    const formData = new FormData();
    formData.append('products_data', JSON.stringify(mergedProducts));
    
    // Append images
    uploadedImages.forEach((img, idx) => {
        formData.append(`image_${idx}`, img.file);
        formData.append(`image_${idx}_mapping`, JSON.stringify({
            productNo: img.productNo,
            type: img.type,
            variantIndex: img.variantIndex
        }));
    });
    
    try {
        const response = await fetch('ajax/save_bulk_products.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            document.getElementById('saveStatus').innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>Products saved successfully!';
            setTimeout(() => {
                window.location.href = 'products.php';
            }, 2000);
        } else {
            throw new Error(result.message || 'Unknown error occurred');
        }
    } catch (error) {
        document.getElementById('saveStatus').innerHTML = `<i class="fas fa-times-circle text-danger me-2"></i>Error: ${error.message}`;
    }
}
