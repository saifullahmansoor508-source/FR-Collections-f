// Custom Dialog System - Modern & Animated
const CustomDialog = {
    show: function(options) {
        const defaults = {
            type: 'info', // success, error, warning, info, danger
            title: 'Notice',
            message: 'This is a message',
            confirmText: 'OK',
            cancelText: 'CANCEL',
            showCancel: false,
            onConfirm: null,
            onCancel: null
        };
        
        const settings = { ...defaults, ...options };
        
        // Remove existing dialogs
        const existing = document.querySelector('.dialog-overlay');
        if (existing) existing.remove();
        
        // Icon mapping
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-times-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle',
            danger: 'fa-trash-alt'
        };
        
        // Create dialog HTML
        const dialogHTML = `
            <div class="dialog-overlay" onclick="CustomDialog.handleOverlayClick(event)">
                <div class="dialog-box">
                    <div class="dialog-icon ${settings.type}">
                        <i class="fas ${icons[settings.type]}"></i>
                    </div>
                    <div class="dialog-content">
                        <h3 class="dialog-title">${settings.title}</h3>
                        <p class="dialog-message">${settings.message}</p>
                    </div>
                    <div class="dialog-buttons">
                        <button class="dialog-btn ${settings.type === 'danger' ? 'dialog-btn-danger' : (settings.type === 'success' ? 'dialog-btn-success' : 'dialog-btn-confirm')}" onclick="CustomDialog.confirm()">
                            <i class="fas ${settings.type === 'danger' ? 'fa-check' : 'fa-check'}"></i>
                            <span>${settings.confirmText}</span>
                        </button>
                        ${settings.showCancel ? `
                            <button class="dialog-btn dialog-btn-cancel" onclick="CustomDialog.cancel()">
                                <i class="fas fa-times"></i>
                                <span>${settings.cancelText}</span>
                            </button>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
        
        // Insert into DOM
        document.body.insertAdjacentHTML('beforeend', dialogHTML);
        
        // Store callbacks
        this.currentConfirm = settings.onConfirm;
        this.currentCancel = settings.onCancel;
        
        // Prevent body scroll
        document.body.style.overflow = 'hidden';
    },
    
    confirm: function() {
        const overlay = document.querySelector('.dialog-overlay');
        if (!overlay) return;
        
        // Animate out
        overlay.style.animation = 'fadeOut 0.2s ease forwards';
        overlay.querySelector('.dialog-box').style.animation = 'slideOut 0.2s ease forwards';
        
        setTimeout(() => {
            overlay.remove();
            document.body.style.overflow = '';
            
            if (this.currentConfirm) {
                this.currentConfirm();
            }
        }, 200);
    },
    
    cancel: function() {
        const overlay = document.querySelector('.dialog-overlay');
        if (!overlay) return;
        
        // Animate out
        overlay.style.animation = 'fadeOut 0.2s ease forwards';
        overlay.querySelector('.dialog-box').style.animation = 'slideOut 0.2s ease forwards';
        
        setTimeout(() => {
            overlay.remove();
            document.body.style.overflow = '';
            
            if (this.currentCancel) {
                this.currentCancel();
            }
        }, 200);
    },
    
    handleOverlayClick: function(event) {
        // Close on overlay click (not on dialog box)
        if (event.target.classList.contains('dialog-overlay')) {
            this.cancel();
        }
    },
    
    // Predefined dialogs
    success: function(title, message, onConfirm) {
        this.show({
            type: 'success',
            title: title,
            message: message,
            confirmText: 'AWESOME!',
            onConfirm: onConfirm
        });
    },
    
    error: function(title, message, onConfirm) {
        this.show({
            type: 'error',
            title: title,
            message: message,
            confirmText: 'OK',
            onConfirm: onConfirm
        });
    },
    
    warning: function(title, message, onConfirm) {
        this.show({
            type: 'warning',
            title: title,
            message: message,
            confirmText: 'GOT IT',
            onConfirm: onConfirm
        });
    },
    
    confirmDialog: function(title, message, onConfirm, onCancel) {
        this.show({
            type: 'warning',
            title: title,
            message: message,
            confirmText: 'YES, CONTINUE',
            cancelText: 'CANCEL',
            showCancel: true,
            onConfirm: onConfirm,
            onCancel: onCancel
        });
    },
    
    delete: function(title, message, onConfirm, onCancel) {
        this.show({
            type: 'danger',
            title: title,
            message: message,
            confirmText: 'YES, DELETE',
            cancelText: 'CANCEL',
            showCancel: true,
            onConfirm: onConfirm,
            onCancel: onCancel
        });
    }
};

// Replace default alert/confirm
window.customAlert = function(message, title = 'Notice') {
    CustomDialog.show({
        type: 'info',
        title: title,
        message: message,
        confirmText: 'OK'
    });
};

window.customConfirm = function(message, title = 'Confirm', onConfirm, onCancel) {
    CustomDialog.confirmDialog(title, message, onConfirm, onCancel);
};
