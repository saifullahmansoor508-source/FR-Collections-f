/**
 * Custom Dialog System - Beautiful Animated Confirmation Dialogs
 * Inspired by modern UI/UX design patterns
 */

const CustomDialog = {
    /**
     * Show a confirmation dialog
     * @param {string} title - Dialog title
     * @param {string} message - Dialog message
     * @param {object} options - Configuration options
     */
    confirm: function(title, message, options = {}) {
        return new Promise((resolve, reject) => {
            // Default options
            const defaults = {
                icon: 'fa-circle-question',
                iconColor: '#8b5cf6',
                confirmText: 'Yes, Confirm',
                confirmColor: 'linear-gradient(135deg, #ec4899, #f43f5e)',
                cancelText: 'Cancel',
                cancelColor: '#e5e7eb',
                cancelTextColor: '#64748b',
                showCancel: true,
                width: '420px'
            };
            
            const config = { ...defaults, ...options };
            
            // Create overlay
            const overlay = document.createElement('div');
            overlay.className = 'custom-dialog-overlay';
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 999999;
                backdrop-filter: blur(5px);
                animation: fadeIn 0.3s ease-out;
            `;
            
            // Create dialog
            const dialog = document.createElement('div');
            dialog.className = 'custom-dialog';
            dialog.style.cssText = `
                background: white;
                border-radius: 24px;
                padding: 40px 30px;
                max-width: ${config.width};
                width: 90%;
                box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
                text-align: center;
                animation: slideUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
                position: relative;
            `;
            
            // Create icon container
            const iconContainer = document.createElement('div');
            iconContainer.style.cssText = `
                width: 80px;
                height: 80px;
                border-radius: 50%;
                background: ${config.iconColor};
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 25px;
                box-shadow: 0 10px 25px ${config.iconColor}40;
                animation: bounceIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
            `;
            
            const icon = document.createElement('i');
            icon.className = `fas ${config.icon}`;
            icon.style.cssText = `
                font-size: 2.5rem;
                color: white;
            `;
            iconContainer.appendChild(icon);
            
            // Create title
            const titleElem = document.createElement('h2');
            titleElem.textContent = title;
            titleElem.style.cssText = `
                font-size: 1.5rem;
                font-weight: 800;
                color: #1e293b;
                margin: 0 0 15px 0;
                animation: fadeInDown 0.5s ease-out 0.2s both;
            `;
            
            // Create message
            const messageElem = document.createElement('p');
            messageElem.textContent = message;
            messageElem.style.cssText = `
                font-size: 1rem;
                color: #64748b;
                margin: 0 0 30px 0;
                line-height: 1.6;
                animation: fadeInDown 0.5s ease-out 0.3s both;
            `;
            
            // Create button container
            const buttonContainer = document.createElement('div');
            buttonContainer.style.cssText = `
                display: flex;
                flex-direction: column;
                gap: 12px;
                animation: fadeInUp 0.5s ease-out 0.4s both;
            `;
            
            // Create cancel button (if enabled)
            if (config.showCancel) {
                const cancelBtn = document.createElement('button');
                cancelBtn.innerHTML = `<i class="fas fa-times"></i> ${config.cancelText}`;
                cancelBtn.style.cssText = `
                    width: 100%;
                    padding: 14px 24px;
                    border: none;
                    border-radius: 12px;
                    background: ${config.cancelColor};
                    color: ${config.cancelTextColor};
                    font-size: 1rem;
                    font-weight: 700;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                `;
                
                cancelBtn.addEventListener('mouseover', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 6px 20px rgba(0, 0, 0, 0.15)';
                });
                
                cancelBtn.addEventListener('mouseout', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = 'none';
                });
                
                cancelBtn.addEventListener('click', function() {
                    closeDialog();
                    resolve(false);
                });
                
                buttonContainer.appendChild(cancelBtn);
            }
            
            // Create confirm button
            const confirmBtn = document.createElement('button');
            confirmBtn.innerHTML = `<i class="fas fa-check"></i> ${config.confirmText}`;
            confirmBtn.style.cssText = `
                width: 100%;
                padding: 14px 24px;
                border: none;
                border-radius: 12px;
                background: ${config.confirmColor};
                color: white;
                font-size: 1rem;
                font-weight: 700;
                cursor: pointer;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                box-shadow: 0 4px 15px rgba(236, 72, 153, 0.3);
            `;
            
            confirmBtn.addEventListener('mouseover', function() {
                this.style.transform = 'translateY(-2px)';
                this.style.boxShadow = '0 6px 20px rgba(236, 72, 153, 0.5)';
            });
            
            confirmBtn.addEventListener('mouseout', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '0 4px 15px rgba(236, 72, 153, 0.3)';
            });
            
            confirmBtn.addEventListener('click', function() {
                closeDialog();
                resolve(true);
            });
            
            buttonContainer.appendChild(confirmBtn);
            
            // Assemble dialog
            dialog.appendChild(iconContainer);
            dialog.appendChild(titleElem);
            dialog.appendChild(messageElem);
            dialog.appendChild(buttonContainer);
            overlay.appendChild(dialog);
            
            // Close dialog function
            function closeDialog() {
                overlay.style.animation = 'fadeOut 0.3s ease-out';
                dialog.style.animation = 'slideDown 0.3s ease-out';
                setTimeout(() => {
                    document.body.removeChild(overlay);
                }, 300);
            }
            
            // Close on overlay click
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    closeDialog();
                    resolve(false);
                }
            });
            
            // Add to page
            document.body.appendChild(overlay);
        });
    },
    
    /**
     * Show success dialog
     */
    success: function(title, message) {
        return this.confirm(title, message, {
            icon: 'fa-circle-check',
            iconColor: '#10b981',
            confirmText: 'Got it!',
            confirmColor: 'linear-gradient(135deg, #10b981, #059669)',
            showCancel: false
        });
    },
    
    /**
     * Show error dialog
     */
    error: function(title, message) {
        return this.confirm(title, message, {
            icon: 'fa-circle-xmark',
            iconColor: '#ef4444',
            confirmText: 'Okay',
            confirmColor: 'linear-gradient(135deg, #ef4444, #dc2626)',
            showCancel: false
        });
    },
    
    /**
     * Show warning dialog
     */
    warning: function(title, message) {
        return this.confirm(title, message, {
            icon: 'fa-triangle-exclamation',
            iconColor: '#f59e0b',
            confirmText: 'Understood',
            confirmColor: 'linear-gradient(135deg, #f59e0b, #d97706)',
            showCancel: false
        });
    },
    
    /**
     * Show delete confirmation
     */
    delete: function(title, message) {
        return this.confirm(title, message, {
            icon: 'fa-trash-can',
            iconColor: '#ec4899',
            confirmText: 'Yes, Delete',
            confirmColor: 'linear-gradient(135deg, #ec4899, #f43f5e)',
            cancelText: 'Cancel'
        });
    }
};

// Add keyframe animations
const style = document.createElement('style');
style.textContent = `
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    @keyframes fadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
    }
    
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(50px) scale(0.9);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }
    
    @keyframes slideDown {
        from {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
        to {
            opacity: 0;
            transform: translateY(50px) scale(0.9);
        }
    }
    
    @keyframes bounceIn {
        0% {
            opacity: 0;
            transform: scale(0.3);
        }
        50% {
            transform: scale(1.05);
        }
        70% {
            transform: scale(0.9);
        }
        100% {
            opacity: 1;
            transform: scale(1);
        }
    }
    
    @keyframes fadeInDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
`;
document.head.appendChild(style);

// Make available globally
window.CustomDialog = CustomDialog;
