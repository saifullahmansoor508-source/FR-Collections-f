// Card Collection System JavaScript

// Collect Card Function
async function collectCard(orderId, cardType, event) {
    event.preventDefault();
    event.stopPropagation();
    
    const button = event.target.closest('button');
    const originalText = button.innerHTML;
    
    // Disable button and show loading
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Collecting...';
    
    try {
        const formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('card_type', cardType);
        
        const response = await fetch('ajax/collect_card.php', {
            method: 'POST',
            body: formData
        });
        
        // Check if response is ok
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // Get response text first to check if it's valid JSON
        const responseText = await response.text();
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (jsonError) {
            console.error('Invalid JSON response:', responseText);
            throw new Error('Server returned invalid response. Please check your database connection.');
        }
        
        if (data.success) {
            // Show congratulations modal
            showCongratulationsModal(data.data);
            
            // Change button to collected state
            button.innerHTML = '<i class="fas fa-check"></i> Collected';
            button.style.background = 'linear-gradient(135deg, #10b981, #34d399)';
            button.style.cursor = 'not-allowed';
            
            // Update card count in shop stats if on shop page
            updateCardCount();
            
        } else {
            throw new Error(data.message || 'Failed to collect card');
        }
        
    } catch (error) {
        console.error('Error collecting card:', error);
        
        // Show error message
        showNotification(error.message || 'Failed to collect card. Please try again.', 'error');
        
        // Restore button
        button.disabled = false;
        button.innerHTML = originalText;
    }
}

// Show Congratulations Modal
function showCongratulationsModal(data) {
    const modal = createCongratulationsModal(data);
    document.body.appendChild(modal);
    
    // Show modal with animation
    setTimeout(() => {
        modal.classList.add('show');
    }, 100);
    
    // Auto close after 5 seconds
    setTimeout(() => {
        closeCongratulationsModal(modal);
    }, 5000);
}

// Create Congratulations Modal
function createCongratulationsModal(data) {
    const isPhaseCompleted = data.is_phase_completed;
    const isGolden = data.gradient_type === 'golden';
    
    const modal = document.createElement('div');
    modal.className = 'card-collection-modal';
    
    let cardClass = 'congratulations-card';
    let iconClass = 'fas fa-credit-card';
    let title = 'Congratulations!';
    let message = '';
    
    if (isPhaseCompleted) {
        cardClass += ' golden-card';
        iconClass = 'fas fa-trophy';
        title = 'Incredible!';
        message = `You have completed your ${getOrdinalNumber(data.phase_number)} phase. Your surprise gift will arrive in 5 to 12 days.`;
    } else {
        const cardNumber = data.total_collected;
        const ordinalCard = getOrdinalNumber(cardNumber);
        message = `Congratulations! You have completed your ${ordinalCard} order. ${data.remaining_cards} more to get an amazing surprise!`;
    }
    
    modal.innerHTML = `
        <div class="${cardClass}">
            <div class="card-icon">
                <i class="${iconClass}"></i>
            </div>
            <h2>${title}</h2>
            <p>${message}</p>
            <button class="close-modal-btn" onclick="closeCongratulationsModal(this.closest('.card-collection-modal'))">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    `;
    
    // Add celebration particles
    if (isPhaseCompleted) {
        addCelebrationParticles(modal);
    }
    
    return modal;
}

// Close Congratulations Modal
function closeCongratulationsModal(modal) {
    modal.classList.remove('show');
    setTimeout(() => {
        if (modal.parentNode) {
            modal.parentNode.removeChild(modal);
        }
    }, 300);
}

// Add Celebration Particles
function addCelebrationParticles(modal) {
    const particleContainer = document.createElement('div');
    particleContainer.className = 'celebration-particles';
    particleContainer.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: 10001;
    `;
    
    // Create confetti particles
    for (let i = 0; i < 50; i++) {
        setTimeout(() => {
            createConfettiParticle(particleContainer);
        }, i * 50);
    }
    
    modal.appendChild(particleContainer);
    
    // Remove particles after animation
    setTimeout(() => {
        if (particleContainer.parentNode) {
            particleContainer.parentNode.removeChild(particleContainer);
        }
    }, 3000);
}

// Create Confetti Particle
function createConfettiParticle(container) {
    const particle = document.createElement('div');
    const colors = ['#ff6b6b', '#4ecdc4', '#45b7d1', '#96ceb4', '#feca57', '#ff9ff3', '#54a0ff'];
    const color = colors[Math.floor(Math.random() * colors.length)];
    
    particle.style.cssText = `
        position: absolute;
        width: 10px;
        height: 10px;
        background: ${color};
        top: -10px;
        left: ${Math.random() * 100}%;
        animation: confettiFall ${2 + Math.random() * 3}s linear forwards;
        border-radius: ${Math.random() > 0.5 ? '50%' : '0'};
        transform: rotate(${Math.random() * 360}deg);
    `;
    
    container.appendChild(particle);
    
    // Remove particle after animation
    setTimeout(() => {
        if (particle.parentNode) {
            particle.parentNode.removeChild(particle);
        }
    }, 5000);
}

// Add confetti animation CSS
const confettiStyle = document.createElement('style');
confettiStyle.textContent = `
    @keyframes confettiFall {
        0% {
            transform: translateY(-10px) rotate(0deg);
            opacity: 1;
        }
        100% {
            transform: translateY(100vh) rotate(720deg);
            opacity: 0;
        }
    }
`;
document.head.appendChild(confettiStyle);

// Get Ordinal Number
function getOrdinalNumber(num) {
    const suffixes = ['th', 'st', 'nd', 'rd'];
    const v = num % 100;
    return num + (suffixes[(v - 20) % 10] || suffixes[v] || suffixes[0]);
}

// Update Card Count in Shop Stats
function updateCardCount() {
    if (typeof updateCardsCount === 'function') {
        updateCardsCount();
    }
}

// Show Notification
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'error' ? '#ef4444' : type === 'success' ? '#10b981' : '#3b82f6'};
        color: white;
        padding: 15px 20px;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        z-index: 10000;
        max-width: 300px;
        opacity: 0;
        transform: translateX(100%);
        transition: all 0.3s ease;
    `;
    
    notification.innerHTML = `
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-${type === 'error' ? 'exclamation-circle' : type === 'success' ? 'check-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Show notification
    setTimeout(() => {
        notification.style.opacity = '1';
        notification.style.transform = 'translateX(0)';
    }, 100);
    
    // Hide notification after 4 seconds
    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 4000);
}

// Initialize card collection system
document.addEventListener('DOMContentLoaded', function() {
    console.log('Card Collection System Initialized');
    
    // Add click handlers for collect card buttons
    document.addEventListener('click', function(e) {
        if (e.target.closest('.btn-collect-card') || e.target.closest('.btn-collect-card-mobile')) {
            const button = e.target.closest('button');
            if (button && !button.disabled) {
                const onclick = button.getAttribute('onclick');
                if (onclick && onclick.includes('collectCard')) {
                    // Let the onclick handler execute
                    return;
                }
            }
        }
    });
});
