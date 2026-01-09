"""
Test suite for Password Reset functionality
Tests: forgot-password, reset-password endpoints, and complete flow
"""
import pytest
import requests
import os
import secrets

BASE_URL = os.environ.get('REACT_APP_BACKEND_URL', '').rstrip('/')

class TestForgotPassword:
    """Tests for /api/auth/forgot-password endpoint"""
    
    def test_forgot_password_valid_email(self):
        """Test forgot password with valid admin email"""
        response = requests.post(f"{BASE_URL}/api/auth/forgot-password", json={
            "email": "thomas@helvegpovlsen.dk"
        })
        assert response.status_code == 200
        data = response.json()
        assert "message" in data
        assert "token" in data  # Token returned for testing purposes
        assert len(data["token"]) > 0
        print(f"✓ Forgot password returns token for valid email")
    
    def test_forgot_password_nonexistent_email(self):
        """Test forgot password with non-existent email - should not reveal if email exists"""
        response = requests.post(f"{BASE_URL}/api/auth/forgot-password", json={
            "email": "nonexistent@example.com"
        })
        assert response.status_code == 200
        data = response.json()
        assert "message" in data
        # Should NOT return token for non-existent email
        assert "token" not in data or data.get("token") is None
        print(f"✓ Forgot password does not reveal non-existent email")
    
    def test_forgot_password_invalid_email_format(self):
        """Test forgot password with invalid email format"""
        response = requests.post(f"{BASE_URL}/api/auth/forgot-password", json={
            "email": "invalid-email"
        })
        # Should return 422 for validation error
        assert response.status_code == 422
        print(f"✓ Forgot password rejects invalid email format")


class TestResetPassword:
    """Tests for /api/auth/reset-password endpoint"""
    
    def test_reset_password_invalid_token(self):
        """Test reset password with invalid token"""
        response = requests.post(f"{BASE_URL}/api/auth/reset-password", json={
            "token": "invalid-token-12345",
            "password": "newpassword123"
        })
        assert response.status_code == 400
        data = response.json()
        assert "detail" in data
        print(f"✓ Reset password rejects invalid token")
    
    def test_reset_password_missing_token(self):
        """Test reset password with missing token"""
        response = requests.post(f"{BASE_URL}/api/auth/reset-password", json={
            "password": "newpassword123"
        })
        assert response.status_code == 422
        print(f"✓ Reset password requires token")
    
    def test_reset_password_missing_password(self):
        """Test reset password with missing password"""
        response = requests.post(f"{BASE_URL}/api/auth/reset-password", json={
            "token": "some-token"
        })
        assert response.status_code == 422
        print(f"✓ Reset password requires password")


class TestCompletePasswordResetFlow:
    """End-to-end tests for complete password reset flow"""
    
    def test_complete_password_reset_flow(self):
        """Test complete flow: request token -> reset password -> login with new password"""
        test_email = f"TEST_reset_{secrets.token_hex(4)}@example.com"
        original_password = "original123"
        new_password = "newpassword456"
        
        # Step 1: Register a test user
        register_response = requests.post(f"{BASE_URL}/api/auth/register", json={
            "email": test_email,
            "password": original_password,
            "display_name": "Test Reset User"
        })
        assert register_response.status_code == 200
        user_data = register_response.json()
        assert "token" in user_data
        print(f"✓ Step 1: Registered test user {test_email}")
        
        # Step 2: Request password reset
        forgot_response = requests.post(f"{BASE_URL}/api/auth/forgot-password", json={
            "email": test_email
        })
        assert forgot_response.status_code == 200
        forgot_data = forgot_response.json()
        assert "token" in forgot_data
        reset_token = forgot_data["token"]
        print(f"✓ Step 2: Got reset token")
        
        # Step 3: Reset password with token
        reset_response = requests.post(f"{BASE_URL}/api/auth/reset-password", json={
            "token": reset_token,
            "password": new_password
        })
        assert reset_response.status_code == 200
        reset_data = reset_response.json()
        assert "message" in reset_data
        print(f"✓ Step 3: Password reset successful")
        
        # Step 4: Verify old password no longer works
        old_login_response = requests.post(f"{BASE_URL}/api/auth/login", json={
            "email": test_email,
            "password": original_password
        })
        assert old_login_response.status_code == 401
        print(f"✓ Step 4: Old password no longer works")
        
        # Step 5: Verify new password works
        new_login_response = requests.post(f"{BASE_URL}/api/auth/login", json={
            "email": test_email,
            "password": new_password
        })
        assert new_login_response.status_code == 200
        login_data = new_login_response.json()
        assert "token" in login_data
        assert "user" in login_data
        assert login_data["user"]["email"] == test_email
        print(f"✓ Step 5: Login with new password successful")
        
        # Cleanup: Delete test user (need admin token)
        admin_login = requests.post(f"{BASE_URL}/api/auth/login", json={
            "email": "thomas@helvegpovlsen.dk",
            "password": "admin123"
        })
        if admin_login.status_code == 200:
            admin_token = admin_login.json()["token"]
            user_id = user_data["user"]["id"]
            requests.delete(
                f"{BASE_URL}/api/admin/users/{user_id}",
                headers={"Authorization": f"Bearer {admin_token}"}
            )
            print(f"✓ Cleanup: Deleted test user")
    
    def test_token_cannot_be_reused(self):
        """Test that reset token cannot be used twice"""
        test_email = f"TEST_reuse_{secrets.token_hex(4)}@example.com"
        
        # Register test user
        register_response = requests.post(f"{BASE_URL}/api/auth/register", json={
            "email": test_email,
            "password": "original123",
            "display_name": "Test Reuse User"
        })
        assert register_response.status_code == 200
        user_data = register_response.json()
        
        # Request password reset
        forgot_response = requests.post(f"{BASE_URL}/api/auth/forgot-password", json={
            "email": test_email
        })
        assert forgot_response.status_code == 200
        reset_token = forgot_response.json()["token"]
        
        # First reset - should succeed
        first_reset = requests.post(f"{BASE_URL}/api/auth/reset-password", json={
            "token": reset_token,
            "password": "newpassword1"
        })
        assert first_reset.status_code == 200
        print(f"✓ First reset succeeded")
        
        # Second reset with same token - should fail
        second_reset = requests.post(f"{BASE_URL}/api/auth/reset-password", json={
            "token": reset_token,
            "password": "newpassword2"
        })
        assert second_reset.status_code == 400
        print(f"✓ Second reset with same token rejected")
        
        # Cleanup
        admin_login = requests.post(f"{BASE_URL}/api/auth/login", json={
            "email": "thomas@helvegpovlsen.dk",
            "password": "admin123"
        })
        if admin_login.status_code == 200:
            admin_token = admin_login.json()["token"]
            user_id = user_data["user"]["id"]
            requests.delete(
                f"{BASE_URL}/api/admin/users/{user_id}",
                headers={"Authorization": f"Bearer {admin_token}"}
            )


class TestAdminPasswordReset:
    """Test password reset for admin user"""
    
    def test_admin_can_request_password_reset(self):
        """Test that admin user can request password reset"""
        response = requests.post(f"{BASE_URL}/api/auth/forgot-password", json={
            "email": "thomas@helvegpovlsen.dk"
        })
        assert response.status_code == 200
        data = response.json()
        assert "token" in data
        print(f"✓ Admin can request password reset")
    
    def test_admin_login_still_works(self):
        """Verify admin login still works with original credentials"""
        response = requests.post(f"{BASE_URL}/api/auth/login", json={
            "email": "thomas@helvegpovlsen.dk",
            "password": "admin123"
        })
        assert response.status_code == 200
        data = response.json()
        assert "token" in data
        assert data["user"]["role"] == "admin"
        print(f"✓ Admin login works with original credentials")


if __name__ == "__main__":
    pytest.main([__file__, "-v", "--tb=short"])
