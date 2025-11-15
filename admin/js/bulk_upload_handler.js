/**
 * FR Collections - Bulk Product Upload Handler
 * Handles 5-step product import process with image auto-mapping
 */

// Global State
let workbookData = null;
let mappedData = {};
let imageMap = {};
let uploadedFiles = [];
let currentStep = 1;
let currentEditingProduct = null;

// Sheet field configurations
const sheetFieldConfigs = {
    products: {
        name: 'Products',
        icon: '📦',
        color: '#3b82f6',
        fields: [
            { key: 'product_no', label: 'Product No', required: true },
            { key: 'name', label: 'Product Name', required: true },
            { key: 'description', label: 'Description', required: false },
            { key: 'category', label: 'Category', required: true },
            { key: 'original_price', label: 'Original Price', required: true },
            { key: 'discounted_price', label: 'Discounted Price', required: false },
            { key: 'commission', label: 'Commission', required: false },
            { key: 'delivery_charges', label: 'Delivery Charges', required: false },
            { key: 'stock', label: 'Stock', required: false },
            { key: 'status', label: 'Status', required: false },
            { key: 'display_location', label: 'Display Location', required: false },
            { key: 'keywords', label: 'Keywords', required: false }
        ]
    },
    features: {
        name: 'Features',
        icon: '⭐',
        color: '#10b981',
        fields: [
            { key: 'product_no', label: 'Product No', required: true },
            { key: 'feature_name', label: 'Feature Name', required: true },
            { key: 'feature_description', label: 'Feature Description', required: false }
        ]
    },
    variants: {
        name: 'Variants',
        icon: '🎨',
        color: '#a855f7',
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
        color: '#f59e0b',
        fields: [
            { key: 'product_no', label: 'Product No', required: true },
            { key: 'reviewer_name', label: 'Reviewer Name', required: true },
            { key: 'rating', label: 'Rating', required: true },
            { key: 'review_text', label: 'Review Text', required: false }
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

// Auto-mapping keywords
const autoMapKeywords = {
    product_no: ['product no', 'product number', 'product id', 'productno', 'productid', 'id', 'no', 'product_no', 'product_id'],
    name: ['name', 'product name', 'title', 'product title', 'product_name'],
    description: ['description', 'desc', 'details', 'product description'],
    category: ['category', 'cat', 'type', 'product category'],
    original_price: ['original price', 'price', 'mrp', 'regular price', 'original_price'],
    discounted_price: ['discounted price', 'discount price', 'sale price', 'discounted_price', 'discount'],
    commission: ['commission', 'commission rate', 'commission (pkr)'],
    delivery_charges: ['delivery charges', 'delivery', 'shipping', 'delivery_charges'],
    stock: ['stock', 'stock count', 'quantity', 'qty', 'stock_count'],
    sold: ['sold', 'units sold', 'sales', 'sales count'],
    status: ['status', 'product status', 'state'],
    display_location: ['display location', 'display', 'location', 'display_location'],
    keywords: ['keywords', 'tags', 'keyword'],
    feature_name: ['feature name', 'feature', 'feature_name'],
    feature_description: ['feature description', 'feature value', 'value', 'feature_description'],
    variant_type: ['variant type', 'type', 'variant_type'],
    variant_name: ['variant name', 'variant', 'option', 'variant_name'],
    sale_price: ['sale price', 'variant price', 'price', 'sale_price'],
    original_price: ['original price', 'price', 'original_price'],
    reviewer_name: ['reviewer name', 'reviewer', 'customer name', 'name', 'reviewer_name'],
    rating: ['rating', 'stars', 'score'],
    review_text: ['review text', 'review', 'comment', 'feedback', 'review_text'],
    units_sold: ['units sold', 'sold', 'sales', 'units_sold'],
    views: ['views', 'view count', 'page views'],
    stock_count: ['stock count', 'stock', 'inventory', 'stock_count'],
    image_url: ['image url', 'image', 'image path', 'image_url', 'img']
};

// ============================================
// STEP 1: Upload Data (Excel/Google Sheets)
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    initializeUploadHandlers();
    initializeImageHandlers();
});

function initializeUploadHandlers() {
    // Upload type switcher
    document.querySelectorAll('input[name="uploadType"]').forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'excel') {
                document.getElementById('excel-upload-section').style.display = 'block';
                document.getElementById('gsheet-upload-section').style.display = 'none';
            } else {
                document.getElementById('excel-upload-section').style.display = 'none';
                document.getElementById('gsheet-upload-section').style.display = 'block';
            }
        });
    });

    // Drag and drop for Excel
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('excelFile');

    dropZone.addEventListener('click', () => fileInput.click());
    
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handleFileUpload(files[0]);
        }
    });

    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            handleFileUpload(e.target.files[0]);
        }
    });
}

function handleFileUpload(file) {
    const validExtensions = ['xlsx', 'xls', 'csv'];
    const fileExtension = file.name.split('.').pop().toLowerCase();

    if (!validExtensions.includes(fileExtension)) {
        alert('Invalid file format. Please upload .xlsx, .xls, or .csv file.');
        return;
    }

    showUploadProgress();

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            
            updateProgress(50, 'Processing sheets...');
            
            setTimeout(() => {
                processWorkbook(workbook);
                updateProgress(100, 'Upload complete!');
                
                setTimeout(() => {
                    hideUploadProgress();
                    goToStep(2);
                }, 800);
            }, 500);
        } catch (error) {
            console.error('Error reading file:', error);
            alert('Error reading file. Please ensure it is a valid Excel file.');
            hideUploadProgress();
        }
    };

    reader.readAsArrayBuffer(file);
}

function loadGoogleSheet() {
    const url = document.getElementById('gsheetUrl').value.trim();
    
    if (!url) {
        alert('Please enter a Google Sheets URL');
        return;
    }

    const match = url.match(/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/);
    if (!match) {
        alert('Invalid Google Sheets URL. Please copy the full URL from your browser.');
        return;
    }

    const sheetId = match[1];
    const exportUrl = `https://docs.google.com/spreadsheets/d/${sheetId}/export?format=xlsx`;

    showUploadProgress();
    updateProgress(30, 'Connecting to Google Sheets...');

    fetch(exportUrl)
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to access Google Sheet. Make sure it is publicly accessible.');
            }
            return response.arrayBuffer();
        })
        .then(data => {
            updateProgress(60, 'Processing sheets...');
            const workbook = XLSX.read(data, { type: 'array' });
            
            setTimeout(() => {
                processWorkbook(workbook);
                updateProgress(100, 'Upload complete!');
                
                setTimeout(() => {
                    hideUploadProgress();
                    goToStep(2);
                }, 800);
            }, 500);
        })
        .catch(error => {
            console.error('Error loading Google Sheet:', error);
            alert(error.message);
            hideUploadProgress();
        });
}

function showUploadProgress() {
    document.getElementById('uploadProgress').style.display = 'block';
}

function hideUploadProgress() {
    document.getElementById('uploadProgress').style.display = 'none';
}

function updateProgress(percent, message) {
    const progressBar = document.getElementById('uploadProgressBar');
    const progressStatus = document.getElementById('progressStatus');
    
    progressBar.style.width = percent + '%';
    progressBar.textContent = percent + '%';
    
    if (message) {
        progressStatus.innerHTML = `<p class="mb-1"><i class="fas fa-spinner fa-spin me-2"></i>${message}</p>`;
    }
}

// ============================================
// STEP 2: Map Data Fields
// ============================================

function processWorkbook(workbook) {
    workbookData = {
        sheets: {},
        products: []
    };

    // Process each sheet
    workbook.SheetNames.forEach((sheetName, index) => {
        const worksheet = workbook.Sheets[sheetName];
        const jsonData = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
        
        if (jsonData.length > 0) {
            const headers = jsonData[0];
            const rows = jsonData.slice(1);
            
            workbookData.sheets[sheetName] = {
                headers: headers,
                rows: rows,
                type: detectSheetType(sheetName, index)
            };
        }
    });

    displayMappingInterface();
}

function detectSheetType(sheetName, index) {
    const lowerName = sheetName.toLowerCase();
    
    if (lowerName.includes('product') && !lowerName.includes('sold')) return 'products';
    if (lowerName.includes('feature')) return 'features';
    if (lowerName.includes('variant')) return 'variants';
    if (lowerName.includes('review')) return 'reviews';
    if (lowerName.includes('sold') || lowerName.includes('info')) return 'soldinfo';
    
    // Fallback based on order
    const types = ['products', 'features', 'variants', 'reviews', 'soldinfo'];
    return types[index] || 'products';
}

function displayMappingInterface() {
    const container = document.getElementById('mappingContainer');
    const navigation = document.getElementById('mappingNavigation');
    
    let html = '';
    
    Object.keys(workbookData.sheets).forEach(sheetName => {
        const sheet = workbookData.sheets[sheetName];
        const config = sheetFieldConfigs[sheet.type];
        
        if (!config) return;
        
        html += generateSheetMappingCard(sheetName, sheet, config);
    });
    
    container.innerHTML = html;
    navigation.style.display = 'flex';
    
    // Auto-map fields
    autoMapAllFields();
}

function generateSheetMappingCard(sheetName, sheet, config) {
    const rowCount = sheet.rows.length;
    
    let html = `
        <div class="card mb-4 border-2" style="border-color: ${config.color} !important;">
            <div class="card-header" style="background: ${config.color}; color: white;">
                <h5 class="mb-0">
                    <span class="me-2">${config.icon}</span>
                    ${config.name} Sheet - "${sheetName}"
                    <span class="badge bg-light text-dark float-end">${rowCount} rows</span>
                </h5>
            </div>
            <div class="card-body">
                <table class="table table-sm mapping-table">
                    <thead>
                        <tr>
                            <th width="30%">System Field</th>
                            <th width="40%">Excel Column</th>
                            <th width="20%">Sample Data</th>
                            <th width="10%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
    `;
    
    config.fields.forEach(field => {
        const mappingId = `${sheetName}_${field.key}`;
        const sampleData = getSampleData(sheet, 0);
        
        html += `
            <tr data-field="${field.key}" data-sheet="${sheetName}">
                <td>
                    <strong>${field.label}</strong>
                    ${field.required ? '<span class="badge bg-danger ms-2">Required</span>' : ''}
                </td>
                <td>
                    <select class="form-select form-select-sm mapping-select" 
                            data-mapping-id="${mappingId}"
                            onchange="updateMapping('${sheetName}', '${field.key}', this.value)">
                        <option value="">-- Select Column --</option>
                        ${sheet.headers.map((header, idx) => 
                            `<option value="${idx}">${header}</option>`
                        ).join('')}
                    </select>
                </td>
                <td>
                    <small class="text-muted sample-data" id="sample_${mappingId}">-</small>
                </td>
                <td>
                    <span class="badge status-badge" id="status_${mappingId}">Unmapped</span>
                </td>
            </tr>
        `;
    });
    
    html += `
                    </tbody>
                </table>
                <div class="mt-3">
                    <button class="btn btn-sm btn-outline-primary" onclick="autoMapSheet('${sheetName}')">
                        <i class="fas fa-magic me-1"></i>Auto-Map This Sheet
                    </button>
                </div>
            </div>
        </div>
    `;
    
    return html;
}

function getSampleData(sheet, columnIndex) {
    if (sheet.rows.length > 0 && sheet.rows[0][columnIndex]) {
        return String(sheet.rows[0][columnIndex]).substring(0, 50);
    }
    return '-';
}

function autoMapAllFields() {
    Object.keys(workbookData.sheets).forEach(sheetName => {
        autoMapSheet(sheetName);
    });
}

function autoMapSheet(sheetName) {
    const sheet = workbookData.sheets[sheetName];
    const config = sheetFieldConfigs[sheet.type];
    
    if (!config) return;
    
    config.fields.forEach(field => {
        const keywords = autoMapKeywords[field.key] || [];
        let matchedIndex = null;
        
        for (let i = 0; i < sheet.headers.length; i++) {
            const header = String(sheet.headers[i]).toLowerCase().trim();
            
            for (const keyword of keywords) {
                if (header === keyword || header.includes(keyword)) {
                    matchedIndex = i;
                    break;
                }
            }
            
            if (matchedIndex !== null) break;
        }
        
        if (matchedIndex !== null) {
            updateMapping(sheetName, field.key, matchedIndex);
            
            // Update UI
            const mappingId = `${sheetName}_${field.key}`;
            const select = document.querySelector(`[data-mapping-id="${mappingId}"]`);
            if (select) {
                select.value = matchedIndex;
            }
        }
    });
}

function updateMapping(sheetName, fieldKey, columnIndex) {
    if (!mappedData[sheetName]) {
        mappedData[sheetName] = {};
    }
    
    mappedData[sheetName][fieldKey] = columnIndex !== '' ? parseInt(columnIndex) : null;
    
    const mappingId = `${sheetName}_${fieldKey}`;
    const sheet = workbookData.sheets[sheetName];
    
    // Update sample data
    const sampleEl = document.getElementById(`sample_${mappingId}`);
    if (sampleEl && columnIndex !== '') {
        const sampleValue = getSampleData(sheet, parseInt(columnIndex));
        sampleEl.textContent = sampleValue;
    }
    
    // Update status badge
    const statusEl = document.getElementById(`status_${mappingId}`);
    if (statusEl) {
        if (columnIndex !== '') {
            statusEl.textContent = 'Mapped';
            statusEl.className = 'badge bg-success';
        } else {
            statusEl.textContent = 'Unmapped';
            statusEl.className = 'badge bg-warning';
        }
    }
}

function saveMappings() {
    // Validate required fields
    let errors = [];
    
    Object.keys(workbookData.sheets).forEach(sheetName => {
        const sheet = workbookData.sheets[sheetName];
        const config = sheetFieldConfigs[sheet.type];
        
        if (!config) return;
        
        config.fields.forEach(field => {
            if (field.required) {
                const mapping = mappedData[sheetName] && mappedData[sheetName][field.key];
                if (mapping === null || mapping === undefined) {
                    errors.push(`${config.name}: "${field.label}" is required but not mapped`);
                }
            }
        });
    });
    
    if (errors.length > 0) {
        alert('Please map all required fields:\n\n' + errors.join('\n'));
        return;
    }
    
    // Process mapped data into products
    processMappedData();
    
    // Move to next step
    goToStep(3);
}

function processMappedData() {
    workbookData.products = [];
    
    // Get products sheet
    let productsSheet = null;
    let productsSheetName = null;
    
    for (const [sheetName, sheet] of Object.entries(workbookData.sheets)) {
        if (sheet.type === 'products') {
            productsSheet = sheet;
            productsSheetName = sheetName;
            break;
        }
    }
    
    if (!productsSheet) {
        alert('No products sheet found!');
        return;
    }
    
    // Process each product row
    const productMapping = mappedData[productsSheetName];
    
    productsSheet.rows.forEach((row, rowIndex) => {
        const product = {
            rowIndex: rowIndex,
            product_no: getFieldValue(row, productMapping, 'product_no'),
            name: getFieldValue(row, productMapping, 'name'),
            description: getFieldValue(row, productMapping, 'description'),
            category: getFieldValue(row, productMapping, 'category'),
            original_price: getFieldValue(row, productMapping, 'original_price'),
            discounted_price: getFieldValue(row, productMapping, 'discounted_price'),
            commission: getFieldValue(row, productMapping, 'commission'),
            delivery_charges: getFieldValue(row, productMapping, 'delivery_charges'),
            stock: getFieldValue(row, productMapping, 'stock'),
            sold: getFieldValue(row, productMapping, 'sold'),
            status: getFieldValue(row, productMapping, 'status') || 'In Stock',
            display_location: getFieldValue(row, productMapping, 'display_location') || 'Shop Page',
            keywords: getFieldValue(row, productMapping, 'keywords'),
            features: [],
            variants: [],
            reviews: [],
            images: {
                primary: null,
                variants: []
            }
        };
        
        // Skip empty rows
        if (!product.product_no || !product.name) {
            return;
        }
        
        workbookData.products.push(product);
    });
    
    // Add features, variants, reviews from other sheets
    addRelatedData('features');
    addRelatedData('variants');
    addRelatedData('reviews');
    addRelatedData('soldinfo');
}

function getFieldValue(row, mapping, fieldKey) {
    if (!mapping || mapping[fieldKey] === null || mapping[fieldKey] === undefined) {
        return null;
    }
    
    const value = row[mapping[fieldKey]];
    return value !== undefined && value !== null ? String(value).trim() : null;
}

function addRelatedData(sheetType) {
    let sheet = null;
    let sheetName = null;
    
    for (const [name, s] of Object.entries(workbookData.sheets)) {
        if (s.type === sheetType) {
            sheet = s;
            sheetName = name;
            break;
        }
    }
    
    if (!sheet) return;
    
    const mapping = mappedData[sheetName];
    if (!mapping) return;
    
    sheet.rows.forEach(row => {
        const productNo = getFieldValue(row, mapping, 'product_no');
        if (!productNo) return;
        
        const product = workbookData.products.find(p => p.product_no === productNo);
        if (!product) return;
        
        if (sheetType === 'features') {
            product.features.push({
                name: getFieldValue(row, mapping, 'feature_name'),
                description: getFieldValue(row, mapping, 'feature_description')
            });
        } else if (sheetType === 'variants') {
            product.variants.push({
                type: getFieldValue(row, mapping, 'variant_type'),
                name: getFieldValue(row, mapping, 'variant_name'),
                sale_price: getFieldValue(row, mapping, 'sale_price'),
                original_price: getFieldValue(row, mapping, 'original_price'),
                image_url: getFieldValue(row, mapping, 'image_url')
            });
        } else if (sheetType === 'reviews') {
            product.reviews.push({
                reviewer_name: getFieldValue(row, mapping, 'reviewer_name'),
                rating: getFieldValue(row, mapping, 'rating'),
                review_text: getFieldValue(row, mapping, 'review_text')
            });
        } else if (sheetType === 'soldinfo') {
            product.units_sold = getFieldValue(row, mapping, 'units_sold');
            product.views = getFieldValue(row, mapping, 'views');
            product.stock_count = getFieldValue(row, mapping, 'stock_count');
        }
    });
}

// ============================================
// STEP 3: Upload Bulk Images
// ============================================

function initializeImageHandlers() {
    console.log('Initializing image handlers...');
    const imageDropZone = document.getElementById('imageDropZone');
    const imageInput = document.getElementById('bulkImages');
    
    if (!imageDropZone || !imageInput) {
        console.warn('Image elements not found:', { imageDropZone, imageInput });
        return;
    }
    
    console.log('Image handlers initialized successfully');
    
    imageDropZone.addEventListener('click', () => imageInput.click());
    
    imageDropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        imageDropZone.classList.add('dragover');
    });
    
    imageDropZone.addEventListener('dragleave', () => {
        imageDropZone.classList.remove('dragover');
    });
    
    imageDropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        imageDropZone.classList.remove('dragover');
        const files = Array.from(e.dataTransfer.files);
        handleBulkImageUpload(files);
    });
    
    imageInput.addEventListener('change', (e) => {
        const files = Array.from(e.target.files);
        handleBulkImageUpload(files);
    });
}

// Wrapper function for the file input onchange event
function handleImageUploadEvent(event) {
    console.log('handleImageUploadEvent called');
    if (event.target.files && event.target.files.length > 0) {
        const files = Array.from(event.target.files);
        handleBulkImageUpload(files);
    }
}

function handleBulkImageUpload(files) {
    console.log('handleBulkImageUpload called with', files.length, 'files');
    
    // Filter valid image files
    const validExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    const validFiles = files.filter(file => {
        const ext = file.name.split('.').pop().toLowerCase();
        return validExtensions.includes(ext);
    });
    
    console.log('Valid image files:', validFiles.length);
    
    if (validFiles.length === 0) {
        alert('No valid image files found. Please upload JPG, PNG, or WebP files.');
        return;
    }
    
    uploadedFiles = validFiles;
    
    // Show processing
    showImageProcessing();
    
    // Process images with delay for UI update
    setTimeout(() => {
        processAndMapImages();
    }, 500);
}

function showImageProcessing() {
    document.getElementById('imageProcessing').style.display = 'block';
    updateImageProgress(0, 'Starting...');
}

function updateImageProgress(percent, message) {
    const progressBar = document.getElementById('imageProgressBar');
    const status = document.getElementById('imageStatus');
    
    progressBar.style.width = percent + '%';
    progressBar.textContent = Math.round(percent) + '%';
    
    if (message) {
        status.innerHTML = `<p class="mb-1"><i class="fas fa-spinner fa-spin me-2"></i>${message}</p>`;
    }
}

function processAndMapImages() {
    console.log('processAndMapImages called');
    console.log('workbookData:', workbookData);
    console.log('uploadedFiles:', uploadedFiles.length);
    
    // Check if workbookData exists
    if (!workbookData || !workbookData.products || workbookData.products.length === 0) {
        alert('No product data found. Please complete Steps 1 and 2 first.');
        document.getElementById('imageProcessing').style.display = 'none';
        return;
    }
    
    console.log('Products available:', workbookData.products.length);
    
    imageMap = {};
    let autoMatchedCount = 0;
    let unmatchedFiles = [];
    
    const totalFiles = uploadedFiles.length;
    
    uploadedFiles.forEach((file, index) => {
        // Update progress
        const progress = ((index + 1) / totalFiles) * 100;
        updateImageProgress(progress, `Processing ${index + 1} of ${totalFiles} images...`);
        
        const mapping = extractImageMapping(file.name);
        
        if (mapping) {
            const product = workbookData.products.find(p => p.product_no === mapping.productNo);
            
            if (product) {
                // Initialize image map for this product
                if (!imageMap[mapping.productNo]) {
                    imageMap[mapping.productNo] = {
                        primary: null,
                        variants: []
                    };
                }
                
                // Create object URL for preview
                const imageUrl = URL.createObjectURL(file);
                
                if (mapping.variantIndex === null) {
                    // Primary image
                    imageMap[mapping.productNo].primary = {
                        file: file,
                        fileName: file.name,
                        url: imageUrl,
                        status: 'auto'
                    };
                    autoMatchedCount++;
                } else {
                    // Variant image
                    imageMap[mapping.productNo].variants[mapping.variantIndex - 1] = {
                        file: file,
                        fileName: file.name,
                        url: imageUrl,
                        status: 'auto',
                        index: mapping.variantIndex
                    };
                    autoMatchedCount++;
                }
            } else {
                unmatchedFiles.push(file);
            }
        } else {
            unmatchedFiles.push(file);
        }
    });
    
    // Hide processing, show results
    setTimeout(() => {
        document.getElementById('imageProcessing').style.display = 'none';
        displayImageMappingResults(autoMatchedCount, unmatchedFiles);
    }, 500);
}

function extractImageMapping(fileName) {
    // Remove extension
    const nameWithoutExt = fileName.replace(/\.(jpg|jpeg|png|webp)$/i, '');
    
    // Pattern: ProductNumber or ProductNumber(VariantIndex)
    // Examples: 1.jpg, 1(1).jpg, 1(2).jpg, 123.jpg, 123(5).jpg
    const primaryPattern = /^(\d+)$/;
    const variantPattern = /^(\d+)\((\d+)\)$/;
    
    let match = nameWithoutExt.match(variantPattern);
    if (match) {
        return {
            productNo: match[1],
            variantIndex: parseInt(match[2])
        };
    }
    
    match = nameWithoutExt.match(primaryPattern);
    if (match) {
        return {
            productNo: match[1],
            variantIndex: null // Primary image
        };
    }
    
    return null;
}

function displayImageMappingResults(autoMatchedCount, unmatchedFiles) {
    // Update summary
    document.getElementById('autoMatchedCount').textContent = autoMatchedCount;
    document.getElementById('manualAssignedCount').textContent = '0';
    document.getElementById('skippedCount').textContent = unmatchedFiles.length;
    document.getElementById('imageSummary').style.display = 'block';
    
    // Display auto-mapped images
    displayAutoMappedImages();
    
    // Display unmapped images
    if (unmatchedFiles.length > 0) {
        displayUnmappedImages(unmatchedFiles);
    }
    
    // Enable continue button if at least some images are mapped
    const continueBtn = document.getElementById('continueToPreview');
    if (autoMatchedCount > 0) {
        continueBtn.disabled = false;
    }
}

function displayAutoMappedImages() {
    const container = document.getElementById('autoMappedGrid');
    
    if (!container) {
        console.error('Auto-mapped grid container not found');
        return;
    }
    
    if (!workbookData || !workbookData.products) {
        console.error('No product data available');
        return;
    }
    
    let html = '';
    
    workbookData.products.forEach(product => {
        const productImages = imageMap[product.product_no];
        
        if (productImages && (productImages.primary || productImages.variants.length > 0)) {
            html += `
                <div class="col-md-6 col-lg-4">
                    <div class="card image-preview-card">
                        <div class="card-body">
                            <h6 class="card-title text-truncate">
                                <span class="badge bg-success">✓</span>
                                ${product.name}
                            </h6>
                            <p class="text-muted small mb-2">Product No: ${product.product_no}</p>
                            
                            ${productImages.primary ? `
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Primary Image:</label>
                                    <img src="${productImages.primary.url}" class="img-fluid rounded" alt="Primary">
                                    <small class="d-block text-muted mt-1">${productImages.primary.fileName}</small>
                                </div>
                            ` : ''}
                            
                            ${productImages.variants.length > 0 ? `
                                <div>
                                    <label class="form-label small fw-bold">Variant Images (${productImages.variants.filter(v => v).length}):</label>
                                    <div class="row g-1">
                                        ${productImages.variants.map((variant, idx) => 
                                            variant ? `
                                                <div class="col-4">
                                                    <img src="${variant.url}" class="img-fluid rounded" alt="Variant ${idx + 1}">
                                                    <small class="d-block text-muted" style="font-size: 10px;">(${idx + 1})</small>
                                                </div>
                                            ` : ''
                                        ).join('')}
                                    </div>
                                </div>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
        }
    });
    
    if (html) {
        document.getElementById('autoMappedSection').style.display = 'block';
        container.innerHTML = html;
    } else {
        document.getElementById('autoMappedSection').style.display = 'none';
    }
}

function displayUnmappedImages(unmatchedFiles) {
    const tbody = document.getElementById('unmappedImagesBody');
    
    if (!tbody) {
        console.error('Unmapped images tbody not found');
        return;
    }
    
    if (!workbookData || !workbookData.products) {
        console.error('No product data available for unmapped images');
        return;
    }
    
    let html = '';
    
    unmatchedFiles.forEach((file, index) => {
        const imageUrl = URL.createObjectURL(file);
        const unmappedId = `unmapped_${index}`;
        
        html += `
            <tr id="row_${unmappedId}">
                <td>
                    <img src="${imageUrl}" style="width: 80px; height: 80px; object-fit: cover; border-radius: 4px;" alt="Preview">
                </td>
                <td>
                    <small>${file.name}</small>
                </td>
                <td>
                    <select class="form-select form-select-sm" id="product_${unmappedId}">
                        <option value="">-- Select Product --</option>
                        ${workbookData.products.map(p => 
                            `<option value="${p.product_no}">${p.product_no} - ${p.name}</option>`
                        ).join('')}
                    </select>
                </td>
                <td>
                    <select class="form-select form-select-sm" id="type_${unmappedId}">
                        <option value="primary">Primary Image</option>
                        <option value="variant">Variant Image</option>
                    </select>
                </td>
                <td>
                    <button class="btn btn-sm btn-success" onclick="assignUnmappedImage('${unmappedId}', ${index})">
                        <i class="fas fa-check"></i> Assign
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="skipUnmappedImage('${unmappedId}')">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
    document.getElementById('unmappedSection').style.display = 'block';
}

function assignUnmappedImage(unmappedId, fileIndex) {
    const productNo = document.getElementById(`product_${unmappedId}`).value;
    const imageType = document.getElementById(`type_${unmappedId}`).value;
    
    if (!productNo) {
        alert('Please select a product');
        return;
    }
    
    const file = uploadedFiles.find(f => {
        const url = URL.createObjectURL(f);
        return document.querySelector(`#row_${unmappedId} img`).src === url;
    });
    
    if (!file) return;
    
    // Initialize image map for product if needed
    if (!imageMap[productNo]) {
        imageMap[productNo] = {
            primary: null,
            variants: []
        };
    }
    
    const imageUrl = URL.createObjectURL(file);
    
    if (imageType === 'primary') {
        imageMap[productNo].primary = {
            file: file,
            fileName: file.name,
            url: imageUrl,
            status: 'manual'
        };
    } else {
        // Add to next available variant slot
        const nextIndex = imageMap[productNo].variants.length;
        imageMap[productNo].variants[nextIndex] = {
            file: file,
            fileName: file.name,
            url: imageUrl,
            status: 'manual',
            index: nextIndex + 1
        };
    }
    
    // Remove from unmapped list
    document.getElementById(`row_${unmappedId}`).remove();
    
    // Update counters
    const currentManual = parseInt(document.getElementById('manualAssignedCount').textContent);
    document.getElementById('manualAssignedCount').textContent = currentManual + 1;
    
    const currentSkipped = parseInt(document.getElementById('skippedCount').textContent);
    document.getElementById('skippedCount').textContent = currentSkipped - 1;
    
    // Refresh auto-mapped display
    displayAutoMappedImages();
    
    // Enable continue button
    document.getElementById('continueToPreview').disabled = false;
    
    // Show success message
    showToast('success', `Image assigned to product ${productNo}`);
}

function skipUnmappedImage(unmappedId) {
    document.getElementById(`row_${unmappedId}`).remove();
    
    // Check if all unmapped images are handled
    const remainingUnmapped = document.querySelectorAll('#unmappedImagesBody tr').length;
    if (remainingUnmapped === 0) {
        document.getElementById('unmappedSection').style.display = 'none';
    }
}

// ============================================
// STEP 4: Preview & Edit
// ============================================

function displayPreviewTable() {
    const tbody = document.getElementById('previewTableBody');
    
    if (!tbody) {
        console.error('Preview table body not found');
        return;
    }
    
    if (!workbookData || !workbookData.products || workbookData.products.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-danger">No product data available</td></tr>';
        return;
    }
    
    let html = '';
    
    workbookData.products.forEach((product, index) => {
        const productImages = imageMap[product.product_no] || { primary: null, variants: [] };
        const variantCount = product.variants.length;
        const imageCount = (productImages.primary ? 1 : 0) + productImages.variants.filter(v => v).length;
        
        html += `
            <tr>
                <td><strong>${product.product_no}</strong></td>
                <td>${product.name}</td>
                <td>Rs. ${product.original_price || 0}</td>
                <td>${product.discounted_price ? 'Rs. ' + product.discounted_price : '-'}</td>
                <td>${product.category}</td>
                <td><span class="badge bg-info">${product.features.length}</span></td>
                <td><span class="badge bg-warning">${variantCount}</span></td>
                <td>
                    ${productImages.primary ? 
                        `<img src="${productImages.primary.url}" class="product-thumb">` : 
                        '<span class="text-danger">No Image</span>'
                    }
                    ${imageCount > 1 ? `<span class="badge bg-secondary">+${imageCount - 1}</span>` : ''}
                </td>
                <td><span class="badge bg-success">${product.reviews.length}</span></td>
                <td>${product.stock || 0}</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="editProductPreview(${index})">
                        <i class="fas fa-edit"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

function editProductPreview(index) {
    currentEditingProduct = index;
    const product = workbookData.products[index];
    const productImages = imageMap[product.product_no] || { primary: null, variants: [] };
    
    const modalBody = document.getElementById('editProductModalBody');
    
    let html = `
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Product Number</label>
                    <input type="text" class="form-control" value="${product.product_no}" disabled>
                </div>
                <div class="mb-3">
                    <label class="form-label">Product Name</label>
                    <input type="text" class="form-control" id="edit_name" value="${product.name}">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="edit_description" rows="3">${product.description || ''}</textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Category</label>
                    <input type="text" class="form-control" id="edit_category" value="${product.category}">
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Original Price</label>
                    <input type="number" class="form-control" id="edit_original_price" value="${product.original_price || ''}">
                </div>
                <div class="mb-3">
                    <label class="form-label">Discounted Price</label>
                    <input type="number" class="form-control" id="edit_discounted_price" value="${product.discounted_price || ''}">
                </div>
                <div class="mb-3">
                    <label class="form-label">Stock</label>
                    <input type="number" class="form-control" id="edit_stock" value="${product.stock || ''}">
                </div>
                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="edit_status">
                        <option value="In Stock" ${product.status === 'In Stock' ? 'selected' : ''}>In Stock</option>
                        <option value="Out of Stock" ${product.status === 'Out of Stock' ? 'selected' : ''}>Out of Stock</option>
                        <option value="Limited" ${product.status === 'Limited' ? 'selected' : ''}>Limited</option>
                    </select>
                </div>
            </div>
        </div>
        
        <hr>
        
        <h6 class="mb-3">Images</h6>
        <div class="row mb-3">
            ${productImages.primary ? `
                <div class="col-md-4">
                    <label class="form-label">Primary Image</label>
                    <img src="${productImages.primary.url}" class="img-fluid rounded" alt="Primary">
                </div>
            ` : '<div class="col-md-12"><p class="text-danger">No primary image assigned</p></div>'}
        </div>
    `;
    
    modalBody.innerHTML = html;
    
    const modal = new bootstrap.Modal(document.getElementById('editProductModal'));
    modal.show();
}

function saveProductEdit() {
    if (currentEditingProduct === null) return;
    
    const product = workbookData.products[currentEditingProduct];
    
    product.name = document.getElementById('edit_name').value;
    product.description = document.getElementById('edit_description').value;
    product.category = document.getElementById('edit_category').value;
    product.original_price = document.getElementById('edit_original_price').value;
    product.discounted_price = document.getElementById('edit_discounted_price').value;
    product.stock = document.getElementById('edit_stock').value;
    product.status = document.getElementById('edit_status').value;
    
    // Refresh preview table
    displayPreviewTable();
    
    // Close modal
    bootstrap.Modal.getInstance(document.getElementById('editProductModal')).hide();
    
    showToast('success', 'Product updated successfully');
}

// ============================================
// STEP 5: Save Products to Database
// ============================================

async function saveAllProducts() {
    const saveProgress = document.getElementById('savingProgress');
    const saveSuccess = document.getElementById('saveSuccess');
    const progressBar = document.getElementById('saveProgressBar');
    const statusText = document.getElementById('saveStatus');
    
    saveProgress.style.display = 'block';
    saveSuccess.style.display = 'none';
    
    const totalProducts = workbookData.products.length;
    let savedCount = 0;
    
    statusText.textContent = `Saving 0 of ${totalProducts} products...`;
    
    for (let i = 0; i < workbookData.products.length; i++) {
        const product = workbookData.products[i];
        const productImages = imageMap[product.product_no] || { primary: null, variants: [] };
        
        try {
            // Upload images first
            const uploadedImagePaths = await uploadProductImages(product.product_no, productImages);
            
            // Prepare product data
            const productData = {
                ...product,
                primary_image: uploadedImagePaths.primary,
                variant_images: uploadedImagePaths.variants
            };
            
            // Save to database
            await saveProductToDatabase(productData);
            
            savedCount++;
            const progress = (savedCount / totalProducts) * 100;
            progressBar.style.width = progress + '%';
            progressBar.textContent = Math.round(progress) + '%';
            statusText.textContent = `Saved ${savedCount} of ${totalProducts} products...`;
            
        } catch (error) {
            console.error('Error saving product:', error);
        }
    }
    
    // Show success
    setTimeout(() => {
        saveProgress.style.display = 'none';
        saveSuccess.style.display = 'block';
        document.getElementById('totalSavedProducts').textContent = savedCount;
    }, 500);
}

async function uploadProductImages(productNo, productImages) {
    const formData = new FormData();
    const uploadedPaths = {
        primary: null,
        variants: []
    };
    
    // Upload primary image
    if (productImages.primary) {
        formData.append('product_no', productNo);
        formData.append('image_type', 'primary');
        formData.append('image', productImages.primary.file);
        
        const response = await fetch('ajax/upload_product_image.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            uploadedPaths.primary = result.path;
        }
    }
    
    // Upload variant images
    for (let i = 0; i < productImages.variants.length; i++) {
        const variant = productImages.variants[i];
        if (variant) {
            const variantFormData = new FormData();
            variantFormData.append('product_no', productNo);
            variantFormData.append('image_type', 'variant');
            variantFormData.append('variant_index', i);
            variantFormData.append('image', variant.file);
            
            const response = await fetch('ajax/upload_product_image.php', {
                method: 'POST',
                body: variantFormData
            });
            
            const result = await response.json();
            if (result.success) {
                uploadedPaths.variants[i] = result.path;
            }
        }
    }
    
    return uploadedPaths;
}

async function saveProductToDatabase(productData) {
    const response = await fetch('ajax/save_bulk_product.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(productData)
    });
    
    const result = await response.json();
    
    if (!result.success) {
        throw new Error(result.message || 'Failed to save product');
    }
    
    return result;
}

// ============================================
// Navigation & Utilities
// ============================================

function goToStep(step) {
    // Hide all steps
    document.querySelectorAll('.step-content').forEach(el => {
        el.classList.remove('active');
    });
    
    // Show target step
    document.getElementById(`step-${step}`).classList.add('active');
    
    // Update step indicators
    document.querySelectorAll('.step-item').forEach(el => {
        const stepNum = parseInt(el.dataset.step);
        
        el.classList.remove('active', 'completed');
        
        if (stepNum === step) {
            el.classList.add('active');
        } else if (stepNum < step) {
            el.classList.add('completed');
        }
    });
    
    currentStep = step;
    
    // Load step content
    if (step === 4) {
        displayPreviewTable();
    } else if (step === 5) {
        setTimeout(() => saveAllProducts(), 500);
    }
}

function showToast(type, message) {
    const alertClass = type === 'success' ? 'alert-success' : type === 'error' ? 'alert-danger' : 'alert-info';
    const icon = type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle';
    
    const toast = document.createElement('div');
    toast.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
    toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    toast.innerHTML = `
        <i class="fas fa-${icon} me-2"></i>${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

// Debug function - call from console: debugBulkUpload()
function debugBulkUpload() {
    console.log('=== BULK UPLOAD DEBUG INFO ===');
    console.log('Current Step:', currentStep);
    console.log('WorkbookData:', workbookData);
    console.log('Products:', workbookData?.products?.length || 0);
    console.log('ImageMap:', imageMap);
    console.log('Uploaded Files:', uploadedFiles.length);
    console.log('Mapped Data:', mappedData);
    console.log('==============================');
    
    if (workbookData && workbookData.products) {
        console.log('First 3 products:', workbookData.products.slice(0, 3));
    }
    
    return {
        currentStep,
        workbookData,
        imageMap,
        uploadedFiles: uploadedFiles.length,
        mappedData
    };
}

// Make functions globally accessible
window.handleBulkImageUpload = handleBulkImageUpload;
window.handleImageUploadEvent = handleImageUploadEvent;
window.debugBulkUpload = debugBulkUpload;
window.goToStep = goToStep;
window.initializeImageHandlers = initializeImageHandlers;
window.processAndMapImages = processAndMapImages;
window.assignUnmappedImage = assignUnmappedImage;
window.skipUnmappedImage = skipUnmappedImage;
window.editProductPreview = editProductPreview;
window.saveProductEdit = saveProductEdit;
window.saveMappings = saveMappings;
window.loadGoogleSheet = loadGoogleSheet;
