<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Failed</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; text-align: center; }
        .failed-container { background: #f8d7da; border: 2px solid #f5c6cb; border-radius: 10px; padding: 30px; }
        .failed-icon { font-size: 60px; color: #dc3545; margin-bottom: 20px; }
        h1 { color: #721c24; margin-bottom: 20px; }
        .details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left; }
        .detail-row { display: flex; justify-content: space-between; margin: 10px 0; padding: 8px 0; border-bottom: 1px solid #eee; }
        .detail-label { font-weight: bold; color: #666; }
        .detail-value { color: #333; }
        .buttons { margin-top: 30px; }
        .btn { padding: 12px 24px; margin: 0 10px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn:hover { opacity: 0.8; }
        .retry-info { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="failed-container">
        <div class="failed-icon">❌</div>
        <h1>Payment Failed</h1>
        <p>Unfortunately, your payment could not be processed.</p>
        
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
                <span class="detail-label">Failure Reason:</span>
                <span class="detail-value" id="failureReason">Loading...</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Transaction Date:</span>
                <span class="detail-value" id="transactionDate">Loading...</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Status:</span>
                <span class="detail-value" style="color: #dc3545; font-weight: bold;">FAILED</span>
            </div>
        </div>
        
        <div class="retry-info">
            <strong>💡 What's Next?</strong><br>
            You can try making the payment again or contact support if the issue persists.
        </div>
        
        <div class="buttons">
            <button onclick="backToApp()" class="btn btn-primary">Continue</button>
        </div>
    </div>

    <script>
        // Get order ID from URL
        const urlParams = new URLSearchParams(window.location.search);
        const orderId = urlParams.get('order_id');
        
        if (orderId) {
            document.getElementById('orderId').textContent = orderId;
            
            // Fetch failure details
            const baseUrl = '<?= getenv("TURN_UP_LIVE_URL") ?: "http://localhost/turn-up" ?>';
            fetch(`${baseUrl}/api/payment/failure/${orderId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('amount').textContent = `₹${data.transaction.net_credited || 'N/A'}`;
                        document.getElementById('failureReason').textContent = data.failure_details.error_description || 'Payment cancelled';
                        document.getElementById('transactionDate').textContent = data.failed_at || new Date().toLocaleString();
                    }
                })
                .catch(error => {
                    console.error('Error fetching failure details:', error);
                    document.getElementById('failureReason').textContent = 'Payment cancelled or failed';
                    document.getElementById('transactionDate').textContent = new Date().toLocaleString();
                });
        }
        
        function backToApp() {
            if (window.opener) {
                window.opener.postMessage({type: 'payment_complete', order_id: orderId}, '*');
                window.close();
            } else {
                window.location.href = '/'; // fallback
            }
        }
    </script>
</body>
</html>