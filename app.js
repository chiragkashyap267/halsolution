// ==========================================================================
//   HAL SOLUTION x SCRAP UNCLE APP LOGIC
// ==========================================================================

// Pincode Checker logic (Mocked for Prototype)
function checkPincode() {
    const input = document.getElementById('pincode-input').value;
    const resultDiv = document.getElementById('pincode-result');
    const scheduleBtn = document.getElementById('schedule-btn');
    
    if (input.length !== 6) {
        resultDiv.innerHTML = '<span style="color: var(--danger);"><i class="fas fa-times-circle"></i> Please enter a valid 6-digit pincode.</span>';
        scheduleBtn.style.display = 'none';
        return;
    }
    
    // Simulate API Call
    resultDiv.innerHTML = '<i class="fas fa-spinner fa-spin" style="color: var(--hal-blue);"></i> Checking availability...';
    scheduleBtn.style.display = 'none';
    
    setTimeout(() => {
        // We simulate that we service Delhi NCR (starting with 11 or 201)
        if (input.startsWith('11') || input.startsWith('201') || input.startsWith('122')) {
            resultDiv.innerHTML = '<span style="color: var(--success);"><i class="fas fa-check-circle"></i> Great! We offer free pickups in your area.</span>';
            scheduleBtn.style.display = 'block';
        } else {
            resultDiv.innerHTML = '<span style="color: var(--danger);"><i class="fas fa-exclamation-triangle"></i> Sorry, we currently do not service this pincode. We are expanding rapidly!</span>';
            scheduleBtn.style.display = 'none';
        }
    }, 800);
}

// Modal Logic
function openLoginModal() {
    document.getElementById('login-modal').classList.add('active');
}

function closeLoginModal() {
    document.getElementById('login-modal').classList.remove('active');
}

// Close modal when clicking outside the bottom sheet
window.onclick = function(event) {
    const modal = document.getElementById('login-modal');
    if (event.target === modal) {
        closeLoginModal();
    }
}
