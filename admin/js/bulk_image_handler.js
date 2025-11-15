/**
 * Bulk Image Upload Handler for Step 3
 * 
 * Handles the upload, processing, and assignment of bulk product images
 * based on filename patterns (ProductID (Sequence)[.extension])
 */

let uploadedImageFiles = [];
let processedAssignments = [];
let unassignedImages = [];

/**
 * Handle image upload event when user selects files
 * Supports unlimited images with chunked upload for optimal performance
 */
function handleImageUploadEvent(event) {
    const files = Array.from(event.target.files);
    
    if (!files || files.length === 0) {
        showAlert('Please select at least one image file', 'warning');
        return;
    }
    
    console.log(`Starting upload of ${files.length} images`);
    
    // Show processing indicator
    document.getElementById('imageProcessing').style.display = 'block';
    document.getElementById('imageStatus').innerHTML = `
        <div class="d-flex align-items-center justify-content-between">
            <span>Uploading ${files.length} images...</span>
            <span class="badge bg-primary" id="uploadCounter">0 / ${files.length}</span>
        </div>
    `;
    updateProgressBar(0);
    
    // Upload images in chunks for better performance
    uploadImagesInChunks(files);
}

/**
 * Upload images in chunks to handle large batches (2000+ images)
 * Chunk size: 15 images per batch (safe for default PHP max_file_uploads=20)
 * Note: If you increase max_file_uploads in php.ini, you can increase this to 50+
 */
async function uploadImagesInChunks(files) {
    const CHUNK_SIZE = 15; // Upload 15 images at a time (safe for PHP default limit of 20)
    const totalFiles = files.length;
    const chunks = [];
    
    // Split files into chunks
    for (let i = 0; i < totalFiles; i += CHUNK_SIZE) {
        chunks.push(files.slice(i, i + CHUNK_SIZE));
    }
    
    console.log(`Split ${totalFiles} images into ${chunks.length} chunks of ${CHUNK_SIZE}`);
    
    uploadedImageFiles = [];
    let uploadedCount = 0;
    let errorCount = 0;
    
    // Upload each chunk sequentially
    for (let chunkIndex = 0; chunkIndex < chunks.length; chunkIndex++) {
        const chunk = chunks[chunkIndex];
        const chunkNumber = chunkIndex + 1;
        
        console.log(`Uploading chunk ${chunkNumber}/${chunks.length} (${chunk.length} images)`);
        
        try {
            // Create FormData for this chunk
            const formData = new FormData();
            formData.append('action', 'upload');
            
            for (let i = 0; i < chunk.length; i++) {
                formData.append('images[]', chunk[i]);
            }
            
            // Upload chunk
            const response = await fetch('ajax/bulk_upload_images.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                // Add uploaded files to the collection
                uploadedImageFiles = uploadedImageFiles.concat(data.files);
                uploadedCount += data.uploaded;
                
                // Update progress
                const progress = Math.round((uploadedCount / totalFiles) * 90); // Reserve 10% for processing
                updateProgressBar(progress);
                
                // Update counter
                document.getElementById('uploadCounter').textContent = `${uploadedCount} / ${totalFiles}`;
                document.getElementById('imageStatus').innerHTML = `
                    <div class="d-flex align-items-center justify-content-between">
                        <span>
                            <i class="fas fa-upload me-2"></i>
                            Uploading chunk ${chunkNumber}/${chunks.length}...
                        </span>
                        <span class="badge bg-success" id="uploadCounter">${uploadedCount} / ${totalFiles}</span>
                    </div>
                `;
                
                console.log(`Chunk ${chunkNumber} uploaded successfully: ${data.uploaded} images`);
                
                if (data.errors > 0) {
                    errorCount += data.errors;
                    console.warn(`Chunk ${chunkNumber} had ${data.errors} errors:`, data.errorMessages);
                }
            } else {
                throw new Error(data.message || 'Upload failed');
            }
            
        } catch (error) {
            console.error(`Error uploading chunk ${chunkNumber}:`, error);
            errorCount += chunk.length;
            
            // Continue with next chunk even if this one fails
            showAlert(`Warning: Chunk ${chunkNumber} failed (${chunk.length} images). Continuing with remaining chunks...`, 'warning');
        }
    }
    
    // All chunks processed
    console.log(`Upload complete: ${uploadedCount} uploaded, ${errorCount} errors`);
    
    if (uploadedCount > 0) {
        updateProgressBar(95);
        document.getElementById('imageStatus').innerHTML = `
            <i class="fas fa-check-circle text-success me-2"></i>
            Uploaded ${uploadedCount} images successfully! Processing assignments...
            ${errorCount > 0 ? `<br><small class="text-warning">⚠️ ${errorCount} images failed to upload</small>` : ''}
        `;
        
        // Now process and assign images
        setTimeout(() => processImages(), 500);
    } else {
        showAlert('All uploads failed. Please check your images and try again.', 'error');
        document.getElementById('imageProcessing').style.display = 'none';
    }
}

/**
 * Process uploaded images and assign them to products
 */
function processImages() {
    const formData = new FormData();
    formData.append('action', 'process');
    formData.append('images', JSON.stringify(uploadedImageFiles));
    
    fetch('ajax/bulk_upload_images.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        updateProgressBar(100);
        
        if (data.success) {
            processedAssignments = data.assignments;
            unassignedImages = data.unassignedFiles;
            
            // Show warnings if any
            if (data.warnings && data.warnings.length > 0) {
                console.log('Image Assignment Warnings:', data.warnings);
            }
            
            document.getElementById('imageStatus').innerHTML = `
                <i class="fas fa-check-circle text-success me-2"></i>
                Processing complete! ${data.assigned} products processed.
                ${data.warnings && data.warnings.length > 0 ? '<br><small class="text-warning">⚠️ Some warnings detected</small>' : ''}
            `;
            
            setTimeout(() => {
                document.getElementById('imageProcessing').style.display = 'none';
                displayImageResults();
            }, 1500);
        } else {
            showAlert('Processing failed: ' + data.message, 'error');
            document.getElementById('imageProcessing').style.display = 'none';
        }
    })
    .catch(error => {
        console.error('Processing error:', error);
        showAlert('Processing failed. Please try again.', 'error');
        document.getElementById('imageProcessing').style.display = 'none';
    });
}

/**
 * Display image processing results
 */
function displayImageResults() {
    // Calculate total images
    const totalImages = uploadedImageFiles.length;
    const matchedImages = processedAssignments.reduce((sum, a) => sum + (a.totalProcessed || 0), 0);
    
    // Update summary counts with animation
    animateCount('autoMatchedCount', processedAssignments.length);
    animateCount('manualAssignedCount', 0);
    animateCount('skippedCount', unassignedImages.length);
    
    document.getElementById('imageSummary').style.display = 'block';
    
    // Show auto-matched section if there are assignments
    if (processedAssignments.length > 0) {
        displayAutoMatchedImages();
        const autoSection = document.getElementById('autoMappedSection');
        autoSection.style.display = 'block';
        autoSection.style.animation = 'fadeIn 0.5s ease';
        // Initialize collapse state (closed by default)
        document.getElementById('autoMappedGrid').style.display = 'none';
    }
    
    // Show unmatched section if there are unassigned images
    if (unassignedImages.length > 0) {
        displayUnmatchedImages();
        const unmappedSection = document.getElementById('unmappedSection');
        unmappedSection.style.display = 'block';
        unmappedSection.style.animation = 'fadeIn 0.5s ease 0.2s backwards';
        // Initialize collapse state (closed by default)
        document.querySelector('#unmappedSection .table-responsive').style.display = 'none';
    }
    
    // Show complete upload list for verification
    displayCompleteUploadList();
    
    // Enable continue button (always allow to continue, even with unassigned images)
    document.getElementById('continueToPreview').disabled = false;
    
    // Check if there are any warnings
    const hasWarnings = processedAssignments.some(a => a.hasWarnings);
    
    if (unassignedImages.length === 0 && !hasWarnings) {
        showAlert('<i class="fas fa-check-circle me-2"></i><strong>Perfect!</strong> All images successfully assigned! You can now continue to the next step.', 'success');
    } else if (hasWarnings) {
        showAlert('<i class="fas fa-exclamation-triangle me-2"></i><strong>Notice:</strong> Some products have missing variant images. Please check the warnings below.', 'warning');
    } else {
        showAlert(`<i class="fas fa-info-circle me-2"></i><strong>Action Needed:</strong> ${unassignedImages.length} ${unassignedImages.length === 1 ? 'image requires' : 'images require'} manual assignment. You can assign them now or continue to the next step.`, 'info');
    }
}

/**
 * Animate count numbers
 */
function animateCount(elementId, targetValue) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const duration = 1000;
    const steps = 30;
    const increment = targetValue / steps;
    let current = 0;
    
    const timer = setInterval(() => {
        current += increment;
        if (current >= targetValue) {
            element.textContent = targetValue;
            clearInterval(timer);
        } else {
            element.textContent = Math.floor(current);
        }
    }, duration / steps);
}

/**
 * Display auto-matched images in a grid
 */
function displayAutoMatchedImages() {
    const grid = document.getElementById('autoMappedGrid');
    let html = '';
    
    processedAssignments.forEach(assignment => {
        const variantImagesCount = assignment.variantImages ? assignment.variantImages.length : 0;
        const additionalImagesCount = assignment.additionalImages ? assignment.additionalImages.length : 0;
        const variantCount = assignment.variantCount || 0;
        const hasWarnings = assignment.hasWarnings || false;
        const missingVariantImages = assignment.missingVariantImages || [];
        
        // Extract image names without paths and extensions
        const primaryImageName = assignment.primaryImage ? assignment.primaryImage.split('/').pop().replace(/\.[^/.]+$/, '') : null;
        const variantImageNames = assignment.variantImages ? assignment.variantImages.map(img => img ? img.split('/').pop().replace(/\.[^/.]+$/, '') : null) : [];
        const additionalImageNames = assignment.additionalImages ? assignment.additionalImages.map(img => img ? img.split('/').pop().replace(/\.[^/.]+$/, '') : null) : [];
        
        html += `
            <div class="col-md-4 mb-3">
                <div class="card auto-matched-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div style="flex: 1; min-width: 0;">
                                <span class="product-header-badge mb-1 d-inline-block">#${assignment.productId}</span>
                                <p class="text-muted mb-0" style="font-size: 0.72rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 3px;" title="${assignment.productName}">${assignment.productName}</p>
                            </div>
                            <span class="badge ${hasWarnings ? 'warning-badge' : 'success-badge'} ms-2" style="font-size: 0.65rem; padding: 5px 10px; line-height: 1;">
                                <i class="fas fa-${hasWarnings ? 'exclamation-triangle' : 'check'} me-1" style="font-size: 0.6rem;"></i>${hasWarnings ? 'Warning' : 'OK'}
                            </span>
                        </div>
                        
                        ${assignment.primaryImage ? `
                            <div class="mb-2 primary-image-preview position-relative">
                                <img src="../${assignment.primaryImage}" class="img-fluid rounded w-100" style="height: 170px; object-fit: cover;" alt="Primary">
                                <div class="image-overlay-badge">
                                    <i class="fas fa-star"></i>${primaryImageName}
                                </div>
                            </div>
                        ` : '<div class="mb-2 p-3 text-center border border-danger rounded" style="background: #fee2e2;"><span class="badge bg-danger" style="font-size: 0.7rem;">No Primary Image</span></div>'}
                        
                        <div class="row g-2 mb-2">
                            <div class="col-4">
                                <div class="stat-box">
                                    <div class="stat-number ${hasWarnings ? 'text-warning' : 'text-success'}" style="font-size: 1.3rem;">${variantImagesCount}<span style="font-size: 1rem; color: #94a3b8;">/</span>${variantCount}</div>
                                    <div class="stat-label">Variants</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="stat-box">
                                    <div class="stat-number text-info">${additionalImagesCount}</div>
                                    <div class="stat-label">Extra</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="stat-box">
                                    <div class="stat-number text-primary">${assignment.totalProcessed}</div>
                                    <div class="stat-label">Total</div>
                                </div>
                            </div>
                        </div>
                        
                        ${variantImageNames.length > 0 ? `
                            <div class="mb-2 mt-2">
                                <div class="section-header">Variant Images</div>
                                <div class="image-name-list">
                                    ${variantImageNames.map((name, idx) => name ? `
                                        <span class="image-name-badge variant">${name}</span>
                                    ` : `<span class="image-name-badge" style="background: #ef4444; opacity: 0.7; font-size: 0.65rem;">V${idx + 1}: ✗</span>`).join('')}
                                </div>
                            </div>
                        ` : ''}
                        
                        ${additionalImageNames.length > 0 ? `
                            <div class="mb-2">
                                <div class="section-header">Additional Images</div>
                                <div class="image-name-list">
                                    ${additionalImageNames.map(name => `
                                        <span class="image-name-badge additional">${name}</span>
                                    `).join('')}
                                </div>
                            </div>
                        ` : ''}
                        
                        ${hasWarnings ? `
                            <div class="alert-compact alert-warning mb-0 mt-2">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                <strong style="font-size: 0.75rem;">Missing:</strong>
                                <span style="font-size: 0.7rem;">${missingVariantImages.map(num => `V${num}`).join(', ')}</span>
                            </div>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
    });
    
    grid.innerHTML = html;
}

/**
 * Display unmatched images that need manual assignment
 */
function displayUnmatchedImages() {
    const tbody = document.getElementById('unmappedImagesBody');
    let html = '';
    
    unassignedImages.forEach((image, index) => {
        const cleanName = image.originalName.replace(/\.[^/.]+$/, '');
        html += `
            <tr id="unmatched-row-${index}" style="font-size: 0.85rem;">
                <td class="text-center py-2">
                    <img src="../${image.path}" class="rounded" style="width: 50px; height: 50px; object-fit: cover; border: 2px solid #e5e7eb;" alt="Unmatched">
                </td>
                <td class="py-2">
                    <span class="badge bg-secondary" style="font-size: 0.7rem; font-family: 'Courier New', monospace;">${cleanName}</span>
                    ${image.reason ? `<br><small class="text-danger" style="font-size: 0.7rem;"><i class="fas fa-info-circle me-1"></i>${image.reason}</small>` : ''}
                </td>
                <td class="py-2">
                    <select class="form-select form-select-sm" id="product-select-${index}" style="font-size: 0.75rem; padding: 5px 10px;">
                        <option value="">-- Select Product --</option>
                        ${productsData.map(p => `<option value="${p.productNo}">#${p.productNo} - ${p.name}</option>`).join('')}
                    </select>
                </td>
                <td class="py-2">
                    <select class="form-select form-select-sm" id="type-select-${index}" style="font-size: 0.75rem; padding: 5px 10px;">
                        <option value="primary">Primary Image</option>
                        <option value="additional" selected>Additional Image</option>
                        <option value="variant">Variant Image</option>
                    </select>
                    <select class="form-select form-select-sm mt-2" id="variant-select-${index}" style="display: none; font-size: 0.75rem; padding: 5px 10px;">
                        <option value="">-- Select Variant --</option>
                    </select>
                </td>
                <td class="text-center py-2">
                    <button class="btn btn-sm btn-success" onclick="manuallyAssignImage(${index})" style="font-size: 0.75rem; padding: 5px 12px;">
                        <i class="fas fa-check me-1"></i>Assign
                    </button>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
    
    // Add event listeners for type selection
    unassignedImages.forEach((image, index) => {
        const typeSelect = document.getElementById(`type-select-${index}`);
        const productSelect = document.getElementById(`product-select-${index}`);
        const variantSelect = document.getElementById(`variant-select-${index}`);
        
        typeSelect.addEventListener('change', function() {
            if (this.value === 'variant') {
                variantSelect.style.display = 'block';
                loadVariantsForProduct(productSelect.value, variantSelect);
            } else {
                variantSelect.style.display = 'none';
            }
        });
        
        productSelect.addEventListener('change', function() {
            if (typeSelect.value === 'variant') {
                loadVariantsForProduct(this.value, variantSelect);
            }
        });
    });
}

/**
 * Load variants for a specific product
 */
function loadVariantsForProduct(productNo, selectElement) {
    if (!productNo) {
        selectElement.innerHTML = '<option value="">-- Select Variant --</option>';
        return;
    }
    
    const product = productsData.find(p => p.productNo == productNo);
    
    if (product && product.variants && product.variants.length > 0) {
        let html = '<option value="">-- Select Variant --</option>';
        product.variants.forEach((variant, idx) => {
            html += `<option value="${idx}">${variant.name} (${variant.type})</option>`;
        });
        selectElement.innerHTML = html;
    } else {
        selectElement.innerHTML = '<option value="">No variants available</option>';
    }
}

/**
 * Manually assign an unmatched image to a product
 */
function manuallyAssignImage(index) {
    const image = unassignedImages[index];
    const productSelect = document.getElementById(`product-select-${index}`);
    const typeSelect = document.getElementById(`type-select-${index}`);
    const variantSelect = document.getElementById(`variant-select-${index}`);
    
    const productNo = productSelect.value;
    const assignmentType = typeSelect.value;
    const variantIndex = variantSelect.value;
    
    if (!productNo) {
        showAlert('Please select a product', 'warning');
        return;
    }
    
    if (assignmentType === 'variant' && !variantIndex) {
        showAlert('Please select a variant', 'warning');
        return;
    }
    
    // Get actual product ID from products data
    const product = productsData.find(p => p.productNo == productNo);
    if (!product) {
        showAlert('Product not found', 'error');
        return;
    }
    
    // Get variant ID if needed
    let variantId = null;
    if (assignmentType === 'variant' && product.variants && product.variants[variantIndex]) {
        // Note: We'll need to fetch the actual variant ID from the database
        // For now, we'll pass the index and handle it on the server
        variantId = variantIndex;
    }
    
    // Send manual assignment request
    const formData = new FormData();
    formData.append('action', 'manual_assign');
    formData.append('image_path', image.path);
    formData.append('product_id', productNo);
    formData.append('assignment_type', assignmentType);
    if (variantId !== null) {
        formData.append('variant_index', variantId); // Send as variant_index not variant_id
    }
    
    fetch('ajax/bulk_upload_images.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            
            // Remove from unassigned list
            document.getElementById(`unmatched-row-${index}`).remove();
            unassignedImages.splice(index, 1);
            
            // Update counts
            const manualCount = parseInt(document.getElementById('manualAssignedCount').textContent);
            document.getElementById('manualAssignedCount').textContent = manualCount + 1;
            document.getElementById('skippedCount').textContent = unassignedImages.length;
            
            // Enable continue if all done
            if (unassignedImages.length === 0) {
                document.getElementById('unmappedSection').style.display = 'none';
                document.getElementById('continueToPreview').disabled = false;
                showAlert('All images have been assigned! You can now continue.', 'success');
            }
        } else {
            showAlert('Assignment failed: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Assignment error:', error);
        showAlert('Assignment failed. Please try again.', 'error');
    });
}

/**
 * Update progress bar
 */
function updateProgressBar(percent) {
    const progressBar = document.getElementById('imageProgressBar');
    if (progressBar) {
        progressBar.style.width = percent + '%';
        progressBar.textContent = percent + '%';
    }
}

/**
 * Show alert message
 */
function showAlert(message, type) {
    const alertDiv = document.createElement('div');
    const alertType = type === 'error' ? 'danger' : type;
    alertDiv.className = `alert alert-compact alert-${alertType} alert-dismissible fade show`;
    alertDiv.style.animation = 'slideInDown 0.3s ease';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" style="font-size: 0.7rem;"></button>
    `;
    
    const container = document.querySelector('.bulk-import-container') || document.querySelector('.container');
    if (container) {
        container.insertBefore(alertDiv, container.firstChild);
        
        // Smooth scroll to alert
        alertDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        
        // Auto-dismiss after 6 seconds
        setTimeout(() => {
            alertDiv.style.animation = 'slideOutUp 0.3s ease';
            setTimeout(() => alertDiv.remove(), 300);
        }, 6000);
    }
}

/**
 * Display complete uploaded images list for verification
 */
function displayCompleteUploadList() {
    const listContainer = document.getElementById('uploadedImagesList');
    const contentContainer = document.getElementById('uploadedImagesContent');
    
    if (!uploadedImageFiles || uploadedImageFiles.length === 0) {
        listContainer.style.display = 'none';
        return;
    }
    
    // Group images by product ID for better organization
    const groupedByProduct = {};
    uploadedImageFiles.forEach(file => {
        const prodId = file.productId || 'unmatched';
        if (!groupedByProduct[prodId]) {
            groupedByProduct[prodId] = [];
        }
        groupedByProduct[prodId].push(file);
    });
    
    let html = '';
    
    // Display matched images grouped by product
    Object.keys(groupedByProduct).sort((a, b) => {
        if (a === 'unmatched') return 1;
        if (b === 'unmatched') return -1;
        return parseInt(a) - parseInt(b);
    }).forEach(productId => {
        const files = groupedByProduct[productId];
        const isUnmatched = productId === 'unmatched';
        
        html += `
            <div class="col-12 mb-3">
                <div class="card image-product-card">
                    <div class="card-header ${isUnmatched ? 'gradient-warning' : 'gradient-success'}">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-${isUnmatched ? 'exclamation-triangle' : 'box'} me-2"></i>
                                <strong>${isUnmatched ? 'Unmatched Images' : `Product #${productId}`}</strong>
                            </div>
                            <span class="badge bg-white bg-opacity-25" style="font-size: 0.7rem; backdrop-filter: blur(10px);">${files.length} ${files.length === 1 ? 'img' : 'imgs'}</span>
                        </div>
                    </div>
                    <div class="card-body p-3" style="background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);">
                        <div class="image-badge-container">
                            ${files.map(file => {
                                // Extract clean name without extension: "1 (1).jpg" → "1 (1)"
                                const cleanName = file.originalName.replace(/\.[^/.]+$/, '');
                                const sequenceLabel = file.sequence ? ` - ${file.sequence === 1 ? 'Primary' : file.sequence === 2 ? 'Variant 1' : file.sequence === 3 ? 'Variant 2' : file.sequence === 4 ? 'Variant 3' : `Image ${file.sequence}`}` : '';
                                
                                // Determine badge gradient based on sequence
                                let badgeClass = 'additional';
                                if (file.sequence === 1) badgeClass = 'primary';
                                else if (file.sequence >= 2 && file.sequence <= 4) badgeClass = 'variant';
                                
                                return `
                                    <span class="image-name-badge ${badgeClass}" 
                                          title="${file.originalName}${sequenceLabel}"
                                          onclick="showImagePreview('../${file.path}', '${file.originalName}')">
                                        ${file.sequence === 1 ? '<i class="fas fa-star me-1"></i>' : '<i class="fas fa-image me-1"></i>'}${cleanName}
                                    </span>
                                `;
                            }).join('')}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    contentContainer.innerHTML = html;
    listContainer.style.display = 'block';
    listContainer.style.animation = 'fadeIn 0.5s ease 0.4s backwards';
}

/**
 * Show image preview in a modal (simple implementation)
 */
function showImagePreview(imagePath, imageName) {
    const modal = document.createElement('div');
    modal.className = 'modal fade show';
    modal.style.display = 'block';
    modal.style.backgroundColor = 'rgba(0,0,0,0.7)';
    modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">${imageName}</h5>
                    <button type="button" class="btn-close" onclick="this.closest('.modal').remove()"></button>
                </div>
                <div class="modal-body text-center">
                    <img src="${imagePath}" class="img-fluid" alt="${imageName}">
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.remove();
        }
    });
}

/**
 * Merge image assignments with product data
 */
function mergeImagesWithProducts() {
    if (!productsData || !processedAssignments) return;
    
    // Create a map of product assignments
    const assignmentMap = {};
    processedAssignments.forEach(assignment => {
        assignmentMap[assignment.productId] = assignment;
    });
    
    // Merge images into products data
    productsData.forEach(product => {
        const assignment = assignmentMap[product.productNo];
        if (assignment) {
            product.assignedImages = {
                primary: assignment.primaryImage,
                variants: assignment.variantImages || [],
                additional: assignment.additionalImages || [],
                totalProcessed: assignment.totalProcessed
            };
        } else {
            product.assignedImages = {
                primary: null,
                variants: [],
                additional: [],
                totalProcessed: 0
            };
        }
    });
}

/**
 * Reset image upload form
 */
function resetImageUpload() {
    document.getElementById('imageInput').value = '';
    uploadedImageFiles = [];
    processedAssignments = [];
    unassignedImages = [];
    
    document.getElementById('imageProcessing').style.display = 'none';
    document.getElementById('imageSummary').style.display = 'none';
    document.getElementById('autoMappedSection').style.display = 'none';
    document.getElementById('unmappedSection').style.display = 'none';
    document.getElementById('uploadedImagesList').style.display = 'none';
    
    document.getElementById('autoMappedGrid').innerHTML = '';
    document.getElementById('unmappedImagesBody').innerHTML = '';
    document.getElementById('uploadedImagesContent').innerHTML = '';
    
    document.getElementById('continueToPreview').disabled = true;
}
