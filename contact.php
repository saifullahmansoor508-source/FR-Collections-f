<?php
// Process form submission BEFORE any output
require_once 'config/config.php';
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = sanitizeInput($_POST['full_name']);
    $email = sanitizeInput($_POST['email']);
    $subject = sanitizeInput($_POST['subject']);
    $message = sanitizeInput($_POST['message']);
    
    if (empty($full_name) || empty($email) || empty($subject) || empty($message)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $stmt = $db->prepare("INSERT INTO contact_messages (full_name, email, subject, message) VALUES (?, ?, ?, ?)");
        
        if ($stmt->execute([$full_name, $email, $subject, $message])) {
            $success = "message_sent";
            // Clear form data
            $full_name = $email = $subject = $message = '';
        } else {
            $error = "Error sending message. Please try again.";
        }
    }
}

$page_title = "Contact Us";
require_once 'includes/header.php';
?>

<style>
    body {
        background: #f0f2f5
    }

    .contact-section {
        min-height: calc(100vh - 120px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 20px
    }

    .contact-wrapper {
        width: 100%;
        max-width: 950px;
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 15px 40px rgba(0, 0, 0, .1);
        overflow: hidden;
        display: grid;
        grid-template-columns: 1fr 1fr;
        min-height: 650px
    }

    .contact-info-panel {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        padding: 60px 40px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
        overflow: hidden
    }

    .contact-info-panel::before {
        content: '';
        position: absolute;
        bottom: -50px;
        left: -50px;
        width: 200px;
        height: 200px;
        background: url("data:image/svg+xml,%3Csvg width='100' height='100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M 10 90 Q 50 10 90 90 T 170 90' fill='none' stroke='rgba(255,255,255,0.1)' stroke-width='10'/%3E%3C/svg%3E");
        opacity: .5
    }

    .contact-info-panel::after {
        content: '';
        position: absolute;
        top: -60px;
        right: -80px;
        width: 250px;
        height: 250px;
        background: url("data:image/svg+xml,%3Csvg width='100' height='100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M 10 10 Q 50 90 90 10 T 170 10' fill='none' stroke='rgba(255,255,255,0.1)' stroke-width='10'/%3E%3C/svg%3E");
        opacity: .5
    }

    .contact-info-content {
        position: relative;
        z-index: 1;
        color: #fff
    }

    .contact-info-content h2 {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 1.5rem;
        line-height: 1.2
    }

    .contact-info-content p {
        font-size: 1.1rem;
        opacity: .9;
        line-height: 1.6;
        margin-bottom: 2rem
    }

    .contact-info-item {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
        padding: 15px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        backdrop-filter: blur(10px)
    }

    .contact-info-item i {
        font-size: 1.5rem;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        flex-shrink: 0
    }

    .contact-info-item-content h6 {
        margin: 0;
        font-size: 0.9rem;
        opacity: 0.8;
        font-weight: 500
    }

    .contact-info-item-content p {
        margin: 0;
        font-size: 1rem;
        font-weight: 600;
        opacity: 1
    }

    .contact-form-panel {
        padding: 60px 50px;
        display: flex;
        flex-direction: column;
        justify-content: center
    }

    .contact-form h3 {
        font-size: 2rem;
        color: #2c3e50;
        font-weight: 700;
        margin-bottom: 30px
    }

    .form-group {
        position: relative;
        margin-bottom: 20px
    }

    .form-group i {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #95a5a6;
        font-size: 1rem;
        z-index: 1
    }

    .form-group input,
    .form-group textarea {
        width: 100%;
        padding: 14px 15px 14px 45px;
        border: 1px solid #ddd;
        border-radius: 10px;
        font-size: 1rem;
        transition: all .3s ease
    }

    .form-group textarea {
        resize: vertical;
        min-height: 120px;
        padding-top: 14px
    }

    .form-group input:focus,
    .form-group textarea:focus {
        outline: 0;
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, .1)
    }

    .form-group input:focus~i,
    .form-group textarea:focus~i {
        color: #1e3a8a
    }

    .contact-button {
        width: 100%;
        padding: 15px;
        background: #1e3a8a;
        color: #fff;
        border: none;
        border-radius: 50px;
        font-size: 1.1rem;
        font-weight: 700;
        cursor: pointer;
        transition: all .3s ease;
        margin-top: 10px
    }

    .contact-button:hover {
        background: #1e40af;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(30, 58, 138, .3)
    }

    .alert {
        padding: 15px 20px;
        border-radius: 50px;
        margin-bottom: 20px;
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 500;
        animation: slideIn 0.3s ease-out
    }

    .alert-danger {
        background: #ef4444;
        color: #fff;
        border: none
    }

    .alert-success {
        background: #10b981;
        color: #fff;
        border: none
    }

    .alert i {
        font-size: 1.2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        flex-shrink: 0
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px)
        }
        to {
            opacity: 1;
            transform: translateY(0)
        }
    }

    /* FAQ Section Styles */
    .faq-section {
        padding: 60px 20px;
        background: #fff;
        margin-top: 40px
    }

    .faq-header {
        text-align: center;
        margin-bottom: 50px
    }

    .faq-header h2 {
        font-size: 2.5rem;
        font-weight: 700;
        color: #1e3a8a;
        margin-bottom: 15px
    }

    .faq-header p {
        font-size: 1.1rem;
        color: #666
    }

    .faq-container {
        max-width: 900px;
        margin: 0 auto
    }

    .faq-item {
        background: #fff;
        border: 2px solid #e5e7eb;
        border-radius: 15px;
        margin-bottom: 20px;
        overflow: hidden;
        transition: all 0.3s ease
    }

    .faq-item:hover {
        border-color: #1e3a8a;
        box-shadow: 0 5px 15px rgba(30, 58, 138, 0.1)
    }

    .faq-question {
        width: 100%;
        padding: 20px 25px;
        background: transparent;
        border: none;
        text-align: left;
        font-size: 1.1rem;
        font-weight: 600;
        color: #2c3e50;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.3s ease
    }

    .faq-question:hover {
        color: #1e3a8a
    }

    .faq-question.active {
        color: #1e3a8a;
        background: #f8f9fa
    }

    .faq-icon {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: #1e3a8a;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        transition: transform 0.3s ease;
        flex-shrink: 0
    }

    .faq-question.active .faq-icon {
        transform: rotate(180deg);
        background: #2563eb
    }

    .faq-answer {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease, padding 0.3s ease
    }

    .faq-answer.show {
        max-height: 300px;
        padding: 0 25px 20px 25px
    }

    .faq-answer p {
        color: #666;
        line-height: 1.7;
        margin: 0
    }

    @media (max-width:992px) {
        .contact-wrapper {
            grid-template-columns: 1fr
        }

        .contact-info-panel {
            padding: 50px 30px
        }

        .contact-form-panel {
            padding: 50px 30px
        }
    }

    @media (max-width:768px) {
        .contact-section {
            padding: 20px 15px
        }

        .contact-wrapper {
            max-width: 450px
        }

        .contact-info-panel {
            display: none
        }

        .contact-form-panel {
            padding: 40px 25px
        }

        .contact-form h3 {
            font-size: 1.8rem
        }

        .faq-header h2 {
            font-size: 2rem
        }
    }

    @media (max-width:480px) {
        .contact-wrapper {
            border-radius: 0
        }

        .contact-form-panel {
            padding: 30px 20px
        }
    }
</style>

<section class="contact-section">
    <div class="contact-wrapper">
        <div class="contact-info-panel">
            <div class="contact-info-content">
                <h2>Contact Us</h2>
                <p>Send us a message, and we'll get back to you soon.</p>
                
                <div class="contact-info-item">
                    <i class="fas fa-envelope"></i>
                    <div class="contact-info-item-content">
                        <h6>Email Address</h6>
                        <p><?php echo ADMIN_EMAIL; ?></p>
                    </div>
                </div>
                
                <div class="contact-info-item">
                    <i class="fas fa-comments"></i>
                    <div class="contact-info-item-content">
                        <h6>Get in Touch</h6>
                        <p>We're here to help</p>
                    </div>
                </div>
                
                <div class="contact-info-item">
                    <i class="fas fa-question-circle"></i>
                    <div class="contact-info-item-content">
                        <h6>Have Questions?</h6>
                        <p>Check our FAQ below</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="contact-form-panel">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($success === 'message_sent'): ?>
                <div class="alert alert-success" id="success-alert">
                    <i class="fas fa-check-circle"></i>
                    <span>Message sent successfully! We'll respond soon.</span>
                </div>
            <?php endif; ?>
            
            <form class="contact-form" method="POST">
                <h3>Send a Message</h3>
                
                <div class="form-group">
                    <i class="fas fa-user"></i>
                    <input type="text" name="full_name" placeholder="Full Name" value="<?php echo isset($full_name) ? htmlspecialchars($full_name) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="Email Address" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <i class="fas fa-tag"></i>
                    <input type="text" name="subject" placeholder="Subject" value="<?php echo isset($subject) ? htmlspecialchars($subject) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <i class="fas fa-comment" style="top: 28px;"></i>
                    <textarea name="message" placeholder="How can we help you today..." required><?php echo isset($message) ? htmlspecialchars($message) : ''; ?></textarea>
                </div>
                
                <button type="submit" class="contact-button">
                    <i class="fas fa-paper-plane me-2"></i>Send Message
                </button>
            </form>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="faq-section">
    <div class="faq-header">
        <h2>Frequently Asked Questions</h2>
        <p>Quick answers to common questions</p>
    </div>
    
    <div class="faq-container">
        <div class="faq-item">
            <button class="faq-question">
                <span>How do I place an order?</span>
                <span class="faq-icon"><i class="fas fa-chevron-down"></i></span>
            </button>
            <div class="faq-answer">
                <p>Simply browse our products, add items to your cart, and proceed to checkout. You'll need to create an account or sign in to complete your purchase. Once you've entered your delivery details, you can select your preferred payment method and place your order.</p>
            </div>
        </div>
        
        <div class="faq-item">
            <button class="faq-question">
                <span>What payment methods do you accept?</span>
                <span class="faq-icon"><i class="fas fa-chevron-down"></i></span>
            </button>
            <div class="faq-answer">
                <p>We accept JazzCash, Easypaisa, and Upaisa payments. After placing your order, you'll receive payment details to complete the transaction. All payment methods are secure and processed quickly.</p>
            </div>
        </div>
        
        <div class="faq-item">
            <button class="faq-question">
                <span>How can I track my order?</span>
                <span class="faq-icon"><i class="fas fa-chevron-down"></i></span>
            </button>
            <div class="faq-answer">
                <p>You can track your order status by visiting your profile page and checking the "My Orders" section. You'll see real-time updates on your order progress including Pending, Processing, Shipped, and Delivered statuses.</p>
            </div>
        </div>
        
        <div class="faq-item">
            <button class="faq-question">
                <span>How do I become an affiliate?</span>
                <span class="faq-icon"><i class="fas fa-chevron-down"></i></span>
            </button>
            <div class="faq-answer">
                <p>Visit our Affiliate page and sign up for an account. You'll need to provide your contact information and address. Once registered, you'll receive a unique Partner ID to start earning 10% commission on sales you generate.</p>
            </div>
        </div>
        
        <div class="faq-item">
            <button class="faq-question">
                <span>Can I request a specific product?</span>
                <span class="faq-icon"><i class="fas fa-chevron-down"></i></span>
            </button>
            <div class="faq-answer">
                <p>Yes! You can request products through your profile page under the "Request Product" section. Provide details about the product you're looking for, and we'll review your request and add it to our catalog if possible.</p>
            </div>
        </div>
        
        <div class="faq-item">
            <button class="faq-question">
                <span>What is your return policy?</span>
                <span class="faq-icon"><i class="fas fa-chevron-down"></i></span>
            </button>
            <div class="faq-answer">
                <p>We want you to be completely satisfied with your purchase. If you're not happy with your order, please contact us within 7 days of delivery to discuss return or exchange options. Items must be in original condition.</p>
            </div>
        </div>
        
        <div class="faq-item">
            <button class="faq-question">
                <span>How long does delivery take?</span>
                <span class="faq-icon"><i class="fas fa-chevron-down"></i></span>
            </button>
            <div class="faq-answer">
                <p>Delivery times vary depending on your location. Typically, orders are processed within 1-2 business days and delivered within 3-7 business days. You'll receive tracking information once your order is shipped.</p>
            </div>
        </div>
        
        <div class="faq-item">
            <button class="faq-question">
                <span>How do I apply a coupon code?</span>
                <span class="faq-icon"><i class="fas fa-chevron-down"></i></span>
            </button>
            <div class="faq-answer">
                <p>During checkout, you'll find a coupon code field in your cart. Enter your code and click "Apply" to get your discount. The discount will be automatically calculated and reflected in your total amount.</p>
            </div>
        </div>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // FAQ Toggle Functionality
        const faqQuestions = document.querySelectorAll('.faq-question');
        
        faqQuestions.forEach(question => {
            question.addEventListener('click', function() {
                const faqItem = this.parentElement;
                const answer = this.nextElementSibling;
                const isActive = this.classList.contains('active');
                
                // Close all other FAQs
                faqQuestions.forEach(q => {
                    q.classList.remove('active');
                    q.nextElementSibling.classList.remove('show');
                });
                
                // Toggle current FAQ
                if (!isActive) {
                    this.classList.add('active');
                    answer.classList.add('show');
                }
            });
        });
        
        // Auto-hide success message after 5 seconds
        const successAlert = document.getElementById('success-alert');
        if (successAlert) {
            setTimeout(function() {
                successAlert.style.display = 'none';
            }, 5000);
        }
    });
</script>

<?php require_once 'includes/footer.php'; ?>
