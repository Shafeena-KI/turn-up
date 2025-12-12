<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; text-align: center; }
        .success-container { background: #d4edda; border: 2px solid #c3e6cb; border-radius: 10px; padding: 30px; }
        .success-icon { font-size: 60px; color: #28a745; margin-bottom: 20px; }
        h1 { color: #155724; margin-bottom: 20px; }
        .details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left; }
        .detail-row { display: flex; justify-content: space-between; margin: 10px 0; padding: 8px 0; border-bottom: 1px solid #eee; }
        .detail-label { font-weight: bold; color: #666; }
        .detail-value { color: #333; }
        .buttons { margin-top: 30px; }
        .btn { padding: 12px 24px; margin: 0 10px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn:hover { opacity: 0.8; }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">✅</div>
        <h1>Payment Successful!</h1>
        <p>Your payment has been processed successfully.</p>
        
        <div class="details" id="paymentDetails">
            <div class="detail-row">
                <span class="detail-label">Order ID:</span>
                <span class="detail-value" id="orderId">Loading...</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Amount:</span>
                <span class="detail-value" id="amount">Loading...</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Payment Method:</span>
                <span class="detail-value" id="paymentMethod">Loading...</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Transaction Date:</span>
                <span class="detail-value" id="transactionDate">Loading...</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Status:</span>
                <span class="detail-value" style="color: #28a745; font-weight: bold;">SUCCESS</span>
            </div>
        </div>
        
        <div class="buttons">
            <a href="/" class="btn btn-primary">Continue</a>
        </div>
    </div>

    <script>
        // Get order ID from URL
        const urlParams = new URLSearchParams(window.location.search);
        const orderId = urlParams.get('order_id');
        
        if (orderId) {
            document.getElementById('orderId').textContent = orderId;
            
            // Fetch transaction details
            const baseUrl = '<?= getenv("TURN_UP_LIVE_URL") ?: "http://localhost/turn-up" ?>';
            fetch(`${baseUrl}/api/payment/verify/${orderId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.order_data) {
                        document.getElementById('amount').textContent = `₹${data.order_data.order_amount || 'N/A'}`;
                        document.getElementById('transactionDate').textContent = new Date().toLocaleString();
                        
                        // Try to get payment method from order data
                        const paymentMethod = data.order_data.payment_method || 'N/A';
                        document.getElementById('paymentMethod').textContent = paymentMethod;
                    }
                })
                .catch(error => {
                    console.error('Error fetching payment details:', error);
                });
        }
    </script>
</body>
</html>