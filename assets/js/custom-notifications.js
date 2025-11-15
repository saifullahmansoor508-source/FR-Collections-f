/**
 * Custom Notification & Modal System
 * Beautiful, interactive replacements for alert(), confirm(), and prompt()
 */

class CustomNotifications {
    constructor() {
        // Wait for DOM to be ready before initializing
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        // Create modal container if it doesn't exist
        if (!document.getElementById('customModalContainer')) {
            const container = document.createElement('div');
            container.id = 'customModalContainer';
            if (document.body) {
                document.body.appendChild(container);
            } else {
                console.error('Document body not ready for custom notifications');
                return;
            }
        }

        // Inject styles
        this.injectStyles();
    }

    /**
     * Show Alert Modal (replaces alert())
     * @param {string} message - The message to display
     * @param {string} type - Type: 'success', 'error', 'warning', 'info'
     * @param {string} title - Optional title
     */
    alert(message, type = 'info', title = null) {
        return new Promise((resolve) => {
            const icons = {
                success: '<i class="fas fa-check-circle"></i>',
                error: '<i class="fas fa-exclamation-circle"></i>',
                warning: '<i class="fas fa-exclamation-triangle"></i>',
                info: '<i class="fas fa-info-circle"></i>'
            };

            const titles = {
                success: title || 'Success',
                error: title || 'Error',
                warning: title || 'Warning',
                info: title || 'Information'
            };

            const modal = this.createModal(`
                <div class="custom-modal-overlay custom-modal-fade-in" id="customAlertModal">
                    <div class="custom-modal custom-modal-${type} custom-modal-scale-in">
                        <div class="custom-modal-icon custom-modal-icon-${type}">
                            ${icons[type]}
                        </div>
                        <div class="custom-modal-header">
                            <h3 class="custom-modal-title">${titles[type]}</h3>
                        </div>
                        <div class="custom-modal-body">
                            <p class="custom-modal-message">${message}</p>
                        </div>
                        <div class="custom-modal-footer">
                            <button class="custom-btn custom-btn-primary custom-btn-ok" id="customAlertOk">
                                <i class="fas fa-check me-2"></i>OK
                            </button>
                        </div>
                    </div>
                </div>
            `);

            document.getElementById('customAlertOk').onclick = () => {
                this.closeModal('customAlertModal');
                resolve(true);
            };

            // Close on overlay click
            modal.onclick = (e) => {
                if (e.target.classList.contains('custom-modal-overlay')) {
                    this.closeModal('customAlertModal');
                    resolve(true);
                }
            };

            // Close on Escape
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    this.closeModal('customAlertModal');
                    resolve(true);
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        });
    }

    /**
     * Show Confirm Modal (replaces confirm())
     * @param {string} message - The message to display
     * @param {string} title - Optional title
     * @param {object} options - Custom button text {confirmText, cancelText}
     */
    confirm(message, title = 'Confirm Action', options = {}) {
        return new Promise((resolve) => {
            const confirmText = options.confirmText || 'Confirm';
            const cancelText = options.cancelText || 'Cancel';
            const type = options.type || 'warning';

            const icons = {
                danger: '<i class="fas fa-trash-alt"></i>',
                warning: '<i class="fas fa-exclamation-triangle"></i>',
                info: '<i class="fas fa-question-circle"></i>'
            };

            const modal = this.createModal(`
                <div class="custom-modal-overlay custom-modal-fade-in" id="customConfirmModal">
                    <div class="custom-modal custom-modal-confirm custom-modal-scale-in">
                        <div class="custom-modal-icon custom-modal-icon-${type}">
                            ${icons[type] || icons.warning}
                        </div>
                        <div class="custom-modal-header">
                            <h3 class="custom-modal-title">${title}</h3>
                        </div>
                        <div class="custom-modal-body">
                            <p class="custom-modal-message">${message}</p>
                        </div>
                        <div class="custom-modal-footer custom-modal-footer-confirm">
                            <button class="custom-btn custom-btn-secondary custom-btn-cancel" id="customConfirmCancel">
                                <i class="fas fa-times me-2"></i>${cancelText}
                            </button>
                            <button class="custom-btn custom-btn-danger custom-btn-confirm" id="customConfirmOk">
                                <i class="fas fa-check me-2"></i>${confirmText}
                            </button>
                        </div>
                    </div>
                </div>
            `);

            document.getElementById('customConfirmOk').onclick = () => {
                this.closeModal('customConfirmModal');
                resolve(true);
            };

            document.getElementById('customConfirmCancel').onclick = () => {
                this.closeModal('customConfirmModal');
                resolve(false);
            };

            // Close on overlay click = cancel
            modal.onclick = (e) => {
                if (e.target.classList.contains('custom-modal-overlay')) {
                    this.closeModal('customConfirmModal');
                    resolve(false);
                }
            };

            // Close on Escape = cancel
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    this.closeModal('customConfirmModal');
                    resolve(false);
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        });
    }

    /**
     * Show Toast Notification (non-blocking)
     * @param {string} message - The message to display
     * @param {string} type - Type: 'success', 'error', 'warning', 'info'
     * @param {number} duration - Duration in milliseconds (default 3000)
     */
    toast(message, type = 'success', duration = 3000) {
        const icons = {
            success: '<i class="fas fa-check-circle"></i>',
            error: '<i class="fas fa-times-circle"></i>',
            warning: '<i class="fas fa-exclamation-triangle"></i>',
            info: '<i class="fas fa-info-circle"></i>'
        };

        const toast = document.createElement('div');
        toast.className = `custom-toast custom-toast-${type} custom-toast-slide-in`;
        toast.innerHTML = `
            <div class="custom-toast-icon">${icons[type]}</div>
            <div class="custom-toast-message">${message}</div>
            <button class="custom-toast-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;

        const container = document.getElementById('customModalContainer');
        container.appendChild(toast);

        // Auto remove after duration
        setTimeout(() => {
            toast.classList.add('custom-toast-slide-out');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    /**
     * Show Prompt Modal (replaces prompt())
     * @param {string} message - The message to display
     * @param {string} defaultValue - Default input value
     * @param {string} title - Optional title
     */
    prompt(message, defaultValue = '', title = 'Input Required') {
        return new Promise((resolve) => {
            const modal = this.createModal(`
                <div class="custom-modal-overlay custom-modal-fade-in" id="customPromptModal">
                    <div class="custom-modal custom-modal-prompt custom-modal-scale-in">
                        <div class="custom-modal-icon custom-modal-icon-info">
                            <i class="fas fa-edit"></i>
                        </div>
                        <div class="custom-modal-header">
                            <h3 class="custom-modal-title">${title}</h3>
                        </div>
                        <div class="custom-modal-body">
                            <p class="custom-modal-message">${message}</p>
                            <input type="text" class="custom-modal-input" id="customPromptInput" value="${defaultValue}" placeholder="Enter value...">
                        </div>
                        <div class="custom-modal-footer custom-modal-footer-confirm">
                            <button class="custom-btn custom-btn-secondary custom-btn-cancel" id="customPromptCancel">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button class="custom-btn custom-btn-primary custom-btn-confirm" id="customPromptOk">
                                <i class="fas fa-check me-2"></i>Submit
                            </button>
                        </div>
                    </div>
                </div>
            `);

            const input = document.getElementById('customPromptInput');
            input.focus();
            input.select();

            const submitValue = () => {
                const value = input.value.trim();
                this.closeModal('customPromptModal');
                resolve(value || null);
            };

            document.getElementById('customPromptOk').onclick = submitValue;

            document.getElementById('customPromptCancel').onclick = () => {
                this.closeModal('customPromptModal');
                resolve(null);
            };

            // Submit on Enter
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    submitValue();
                }
            });

            // Close on Escape = cancel
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    this.closeModal('customPromptModal');
                    resolve(null);
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        });
    }

    createModal(html) {
        const container = document.getElementById('customModalContainer');
        container.insertAdjacentHTML('beforeend', html);
        return container.lastElementChild;
    }

    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('custom-modal-fade-out');
            setTimeout(() => modal.remove(), 300);
        }
    }

    injectStyles() {
        if (document.getElementById('customNotificationStyles')) return;

        const style = document.createElement('style');
        style.id = 'customNotificationStyles';
        style.textContent = `
            /* Custom Modal System Styles */
            #customModalContainer {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 999999;
                pointer-events: none;
            }

            .custom-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.6);
                backdrop-filter: blur(4px);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                z-index: 999999;
                pointer-events: all;
            }

            .custom-modal {
                background: linear-gradient(180deg, #ffffff 0%, #f9fafb 100%);
                border-radius: 24px;
                box-shadow: 0 25px 80px rgba(0, 0, 0, 0.35);
                max-width: 520px;
                width: 100%;
                overflow: hidden;
                position: relative;
                border: 1px solid rgba(255, 255, 255, 0.8);
            }

            .custom-modal::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 4px;
                background: linear-gradient(90deg, #667eea, #764ba2, #f093fb, #667eea);
                background-size: 200% 100%;
                animation: shimmer 3s linear infinite;
            }

            @keyframes shimmer {
                0% { background-position: 0% 0%; }
                100% { background-position: 200% 0%; }
            }

            .custom-modal-icon {
                width: 100px;
                height: 100px;
                margin: 40px auto 20px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 3rem;
                animation: iconBounce 1s ease-in-out infinite, iconGlow 2s ease-in-out infinite;
                position: relative;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            }

            .custom-modal-icon::before {
                content: '';
                position: absolute;
                width: 120%;
                height: 120%;
                border-radius: 50%;
                border: 3px solid currentColor;
                opacity: 0.3;
                animation: ripple 2s ease-out infinite;
            }

            @keyframes iconBounce {
                0%, 100% { transform: translateY(0) rotate(0deg); }
                25% { transform: translateY(-5px) rotate(-5deg); }
                75% { transform: translateY(-5px) rotate(5deg); }
            }

            @keyframes iconGlow {
                0%, 100% { box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2); }
                50% { box-shadow: 0 15px 60px rgba(0, 0, 0, 0.4); }
            }

            @keyframes ripple {
                0% {
                    transform: scale(1);
                    opacity: 0.3;
                }
                100% {
                    transform: scale(1.5);
                    opacity: 0;
                }
            }

            .custom-modal-icon-success {
                background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%);
                color: white;
            }

            .custom-modal-icon-error {
                background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
                color: white;
            }

            .custom-modal-icon-warning {
                background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
                color: #ff6b6b;
            }

            .custom-modal-icon-info {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
            }

            .custom-modal-icon-danger {
                background: linear-gradient(135deg, #f5576c 0%, #f093fb 100%);
                color: white;
            }

            .custom-modal-header {
                text-align: center;
                padding: 0 30px;
            }

            .custom-modal-title {
                font-size: 1.75rem;
                font-weight: 800;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;
                margin: 0 0 10px 0;
                animation: titleSlide 0.6s ease-out;
            }

            @keyframes titleSlide {
                0% {
                    opacity: 0;
                    transform: translateY(-20px);
                }
                100% {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .custom-modal-body {
                padding: 20px 35px 35px;
                text-align: center;
            }

            .custom-modal-message {
                font-size: 1.05rem;
                color: #4b5563;
                line-height: 1.7;
                margin: 0;
                white-space: pre-line;
                animation: messageSlide 0.6s ease-out 0.2s both;
            }

            @keyframes messageSlide {
                0% {
                    opacity: 0;
                    transform: translateY(10px);
                }
                100% {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .custom-modal-input {
                width: 100%;
                padding: 12px 16px;
                border: 2px solid #e5e7eb;
                border-radius: 10px;
                font-size: 1rem;
                margin-top: 15px;
                transition: all 0.3s ease;
            }

            .custom-modal-input:focus {
                outline: none;
                border-color: #3b82f6;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            }

            .custom-modal-footer {
                padding: 0 35px 35px;
                display: flex;
                gap: 15px;
                justify-content: center;
                animation: footerSlide 0.6s ease-out 0.3s both;
            }

            @keyframes footerSlide {
                0% {
                    opacity: 0;
                    transform: translateY(20px);
                }
                100% {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .custom-modal-footer-confirm {
                justify-content: space-between;
            }

            .custom-btn {
                padding: 14px 32px;
                border: none;
                border-radius: 50px;
                font-size: 1rem;
                font-weight: 700;
                cursor: pointer;
                transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                min-width: 140px;
                position: relative;
                overflow: hidden;
                letter-spacing: 0.5px;
                text-transform: uppercase;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            }

            .custom-btn::before {
                content: '';
                position: absolute;
                top: 0;
                left: -100%;
                width: 100%;
                height: 100%;
                background: rgba(255, 255, 255, 0.3);
                transition: left 0.5s ease;
                z-index: 0;
            }

            .custom-btn:hover::before {
                left: 100%;
            }

            .custom-btn span, .custom-btn i {
                position: relative;
                z-index: 1;
            }

            .custom-btn:hover {
                transform: translateY(-3px) scale(1.02);
                box-shadow: 0 12px 35px rgba(0, 0, 0, 0.3);
            }

            .custom-btn:active {
                transform: translateY(-1px) scale(0.98);
                transition: all 0.1s ease;
            }

            /* Animated Gradient Buttons */
            .custom-btn-primary {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
                background-size: 200% 200%;
                color: white;
                animation: gradientShift 3s ease infinite;
                border: 2px solid rgba(255, 255, 255, 0.3);
            }

            .custom-btn-primary:hover {
                animation: gradientShift 1.5s ease infinite, buttonPulse 0.6s ease infinite;
                box-shadow: 0 12px 40px rgba(102, 126, 234, 0.6);
            }

            .custom-btn-danger {
                background: linear-gradient(135deg, #f093fb 0%, #f5576c 50%, #ff6b6b 100%);
                background-size: 200% 200%;
                color: white;
                animation: gradientShift 3s ease infinite;
                border: 2px solid rgba(255, 255, 255, 0.3);
            }

            .custom-btn-danger:hover {
                animation: gradientShift 1.5s ease infinite, buttonPulse 0.6s ease infinite;
                box-shadow: 0 12px 40px rgba(245, 87, 108, 0.6);
            }

            .custom-btn-secondary {
                background: linear-gradient(135deg, #e0e7ff 0%, #f3f4f6 100%);
                color: #4b5563;
                border: 2px solid #d1d5db;
                font-weight: 600;
            }

            .custom-btn-secondary:hover {
                background: linear-gradient(135deg, #c7d2fe 0%, #e5e7eb 100%);
                color: #1f2937;
                border-color: #9ca3af;
                box-shadow: 0 8px 25px rgba(156, 163, 175, 0.3);
            }

            /* Success Button Variant */
            .custom-btn-success {
                background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 50%, #a6c1ee 100%);
                background-size: 200% 200%;
                color: white;
                animation: gradientShift 3s ease infinite;
                border: 2px solid rgba(255, 255, 255, 0.3);
            }

            .custom-btn-success:hover {
                animation: gradientShift 1.5s ease infinite, buttonPulse 0.6s ease infinite;
                box-shadow: 0 12px 40px rgba(132, 250, 176, 0.6);
            }

            @keyframes gradientShift {
                0% { background-position: 0% 50%; }
                50% { background-position: 100% 50%; }
                100% { background-position: 0% 50%; }
            }

            @keyframes buttonPulse {
                0%, 100% { transform: translateY(-3px) scale(1.02); }
                50% { transform: translateY(-3px) scale(1.05); }
            }

            /* Enhanced Modal Animations */
            @keyframes fadeIn {
                from { 
                    opacity: 0; 
                    backdrop-filter: blur(0px);
                }
                to { 
                    opacity: 1; 
                    backdrop-filter: blur(4px);
                }
            }

            @keyframes fadeOut {
                from { 
                    opacity: 1; 
                    backdrop-filter: blur(4px);
                }
                to { 
                    opacity: 0; 
                    backdrop-filter: blur(0px);
                }
            }

            @keyframes scaleIn {
                0% {
                    opacity: 0;
                    transform: scale(0.5) rotateX(30deg);
                }
                50% {
                    transform: scale(1.05) rotateX(-5deg);
                }
                100% {
                    opacity: 1;
                    transform: scale(1) rotateX(0deg);
                }
            }

            .custom-modal-fade-in {
                animation: fadeIn 0.4s ease-out;
            }

            .custom-modal-fade-out {
                animation: fadeOut 0.3s ease-out;
            }

            .custom-modal-scale-in .custom-modal {
                animation: scaleIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
                transform-style: preserve-3d;
                perspective: 1000px;
            }

            /* Enhanced Toast Notifications */
            .custom-toast {
                position: fixed;
                top: 20px;
                right: 20px;
                background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
                padding: 18px 24px;
                border-radius: 16px;
                box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25);
                display: flex;
                align-items: center;
                gap: 14px;
                min-width: 320px;
                max-width: 500px;
                z-index: 1000000;
                pointer-events: all;
                border: 2px solid;
                overflow: hidden;
                position: relative;
            }

            .custom-toast::before {
                content: '';
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 6px;
                background: linear-gradient(180deg, currentColor 0%, transparent 100%);
                animation: toastPulse 2s ease-in-out infinite;
            }

            @keyframes toastPulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.6; }
            }

            .custom-toast-success {
                border-color: rgba(132, 250, 176, 0.5);
                color: #84fab0;
            }

            .custom-toast-error {
                border-color: rgba(247, 112, 154, 0.5);
                color: #f7709a;
            }

            .custom-toast-warning {
                border-color: rgba(252, 182, 159, 0.5);
                color: #fcb69f;
            }

            .custom-toast-info {
                border-color: rgba(102, 126, 234, 0.5);
                color: #667eea;
            }

            .custom-toast-icon {
                font-size: 1.75rem;
                flex-shrink: 0;
                animation: iconSpin 0.5s ease-out;
            }

            @keyframes iconSpin {
                0% {
                    transform: scale(0) rotate(-180deg);
                    opacity: 0;
                }
                100% {
                    transform: scale(1) rotate(0deg);
                    opacity: 1;
                }
            }

            .custom-toast-success .custom-toast-icon {
                color: #84fab0;
                filter: drop-shadow(0 0 8px rgba(132, 250, 176, 0.5));
            }

            .custom-toast-error .custom-toast-icon {
                color: #f7709a;
                filter: drop-shadow(0 0 8px rgba(247, 112, 154, 0.5));
            }

            .custom-toast-warning .custom-toast-icon {
                color: #fcb69f;
                filter: drop-shadow(0 0 8px rgba(252, 182, 159, 0.5));
            }

            .custom-toast-info .custom-toast-icon {
                color: #667eea;
                filter: drop-shadow(0 0 8px rgba(102, 126, 234, 0.5));
            }

            .custom-toast-message {
                flex: 1;
                color: #1f2937;
                font-weight: 600;
                font-size: 0.95rem;
            }

            .custom-toast-close {
                background: none;
                border: none;
                color: #9ca3af;
                cursor: pointer;
                padding: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: color 0.2s;
            }

            .custom-toast-close:hover {
                color: #374151;
            }

            @keyframes slideInRight {
                0% {
                    transform: translateX(400px) scale(0.8);
                    opacity: 0;
                }
                60% {
                    transform: translateX(-10px) scale(1.02);
                }
                100% {
                    transform: translateX(0) scale(1);
                    opacity: 1;
                }
            }

            @keyframes slideOutRight {
                0% {
                    transform: translateX(0) scale(1);
                    opacity: 1;
                }
                100% {
                    transform: translateX(400px) scale(0.8);
                    opacity: 0;
                }
            }

            .custom-toast-slide-in {
                animation: slideInRight 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            }

            .custom-toast-slide-out {
                animation: slideOutRight 0.3s ease-in;
            }

            /* Mobile Responsive */
            @media (max-width: 640px) {
                .custom-modal {
                    max-width: 90%;
                    margin: 20px;
                }

                .custom-modal-icon {
                    width: 60px;
                    height: 60px;
                    font-size: 2rem;
                    margin: 20px auto 15px;
                }

                .custom-modal-title {
                    font-size: 1.25rem;
                }

                .custom-modal-footer {
                    flex-direction: column;
                }

                .custom-btn {
                    width: 100%;
                }

                .custom-toast {
                    left: 10px;
                    right: 10px;
                    min-width: auto;
                }
            }
        `;
        document.head.appendChild(style);
    }
}

// Initialize and expose globally
const customNotify = new CustomNotifications();

// Expose as window object for easy access
window.customNotify = customNotify;

// Convenience methods
window.showAlert = (message, type, title) => customNotify.alert(message, type, title);
window.showConfirm = (message, title, options) => customNotify.confirm(message, title, options);
window.showToast = (message, type, duration) => customNotify.toast(message, type, duration);
window.showPrompt = (message, defaultValue, title) => customNotify.prompt(message, defaultValue, title);
