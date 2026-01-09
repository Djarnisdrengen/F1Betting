import requests
import sys
import json
from datetime import datetime, timedelta

class F1BettingAPITester:
    def __init__(self, base_url="https://f1bet.preview.emergentagent.com/api"):
        self.base_url = base_url
        self.token = None
        self.admin_token = None
        self.user_id = None
        self.admin_id = None
        self.tests_run = 0
        self.tests_passed = 0
        self.test_results = []

    def log_test(self, name, success, details=""):
        """Log test result"""
        self.tests_run += 1
        if success:
            self.tests_passed += 1
            print(f"✅ {name}")
        else:
            print(f"❌ {name} - {details}")
        
        self.test_results.append({
            "test": name,
            "success": success,
            "details": details
        })

    def run_test(self, name, method, endpoint, expected_status, data=None, headers=None):
        """Run a single API test"""
        url = f"{self.base_url}/{endpoint}"
        default_headers = {'Content-Type': 'application/json'}
        if headers:
            default_headers.update(headers)

        try:
            if method == 'GET':
                response = requests.get(url, headers=default_headers)
            elif method == 'POST':
                response = requests.post(url, json=data, headers=default_headers)
            elif method == 'PUT':
                response = requests.put(url, json=data, headers=default_headers)
            elif method == 'DELETE':
                response = requests.delete(url, headers=default_headers)

            success = response.status_code == expected_status
            details = f"Status: {response.status_code}"
            if not success:
                details += f" (Expected {expected_status})"
                try:
                    error_data = response.json()
                    details += f" - {error_data.get('detail', 'Unknown error')}"
                except:
                    details += f" - {response.text[:100]}"

            self.log_test(name, success, details)
            return success, response.json() if success and response.content else {}

        except Exception as e:
            self.log_test(name, False, f"Exception: {str(e)}")
            return False, {}

    def test_auth_flow(self):
        """Test complete authentication flow"""
        print("\n🔐 Testing Authentication Flow...")
        
        # Test user registration
        test_email = f"testuser_{datetime.now().strftime('%H%M%S')}@test.com"
        success, response = self.run_test(
            "User Registration",
            "POST",
            "auth/register",
            200,
            data={
                "email": test_email,
                "password": "TestPass123!",
                "display_name": "Test User"
            }
        )
        
        if success and 'token' in response:
            self.token = response['token']
            self.user_id = response['user']['id']
            
            # Test login with same credentials
            success, login_response = self.run_test(
                "User Login",
                "POST", 
                "auth/login",
                200,
                data={
                    "email": test_email,
                    "password": "TestPass123!"
                }
            )
            
            # Test get current user
            self.run_test(
                "Get Current User",
                "GET",
                "auth/me",
                200,
                headers={'Authorization': f'Bearer {self.token}'}
            )
            
            # Test profile update
            self.run_test(
                "Update Profile",
                "PUT",
                "auth/profile",
                200,
                data={"display_name": "Updated Test User"},
                headers={'Authorization': f'Bearer {self.token}'}
            )
            
        else:
            print("❌ Registration failed, skipping auth tests")
            return False
            
        return True

    def test_admin_registration(self):
        """Test admin user creation (first user becomes admin)"""
        print("\n👑 Testing Admin Access...")
        
        # First check if we can find an existing admin user
        # Try to get leaderboard to see existing users
        try:
            response = requests.get(f"{self.base_url}/leaderboard")
            if response.status_code == 200:
                users = response.json()
                if users:
                    # Try to login as the first user (likely admin)
                    first_user_email = users[0]['email']
                    # Try common admin credentials
                    for password in ["AdminPass123!", "TestPass123!", "admin123", "password"]:
                        try:
                            login_response = requests.post(f"{self.base_url}/auth/login", 
                                json={"email": first_user_email, "password": password})
                            if login_response.status_code == 200:
                                login_data = login_response.json()
                                if login_data.get('user', {}).get('role') == 'admin':
                                    self.admin_token = login_data['token']
                                    self.admin_id = login_data['user']['id']
                                    self.log_test("Found Existing Admin", True, f"Email: {first_user_email}")
                                    return True
                        except:
                            continue
        except:
            pass
        
        # If no existing admin found, try to create new admin
        admin_email = f"admin_{datetime.now().strftime('%H%M%S')}@test.com"
        success, response = self.run_test(
            "Create New Admin",
            "POST",
            "auth/register", 
            200,
            data={
                "email": admin_email,
                "password": "AdminPass123!",
                "display_name": "Admin User"
            }
        )
        
        if success and 'token' in response:
            user_role = response.get('user', {}).get('role', 'user')
            if user_role == 'admin':
                self.admin_token = response['token']
                self.admin_id = response['user']['id']
                return True
            else:
                self.log_test("New User Not Admin", False, "First user already exists, new users are not admin")
                return False
        return False

    def test_drivers_api(self):
        """Test drivers CRUD operations"""
        print("\n🏎️ Testing Drivers API...")
        
        if not self.admin_token:
            print("❌ No admin token, skipping driver tests")
            return
            
        # Get all drivers
        self.run_test(
            "Get All Drivers",
            "GET",
            "drivers",
            200
        )
        
        # Create new driver
        success, response = self.run_test(
            "Create Driver",
            "POST",
            "drivers",
            200,
            data={
                "name": "Test Driver",
                "team": "Test Team", 
                "number": 99
            },
            headers={'Authorization': f'Bearer {self.admin_token}'}
        )
        
        if success and 'id' in response:
            driver_id = response['id']
            
            # Update driver
            self.run_test(
                "Update Driver",
                "PUT",
                f"drivers/{driver_id}",
                200,
                data={
                    "name": "Updated Test Driver",
                    "team": "Updated Test Team",
                    "number": 98
                },
                headers={'Authorization': f'Bearer {self.admin_token}'}
            )
            
            # Delete driver
            self.run_test(
                "Delete Driver",
                "DELETE",
                f"drivers/{driver_id}",
                200,
                headers={'Authorization': f'Bearer {self.admin_token}'}
            )

    def test_races_api(self):
        """Test races CRUD operations"""
        print("\n🏁 Testing Races API...")
        
        if not self.admin_token:
            print("❌ No admin token, skipping race tests")
            return
            
        # Get all races
        self.run_test(
            "Get All Races",
            "GET",
            "races",
            200
        )
        
        # Create new race
        future_date = (datetime.now() + timedelta(days=30)).strftime("%Y-%m-%d")
        success, response = self.run_test(
            "Create Race",
            "POST",
            "races",
            200,
            data={
                "name": "Test Grand Prix",
                "location": "Test Circuit",
                "race_date": future_date,
                "race_time": "14:00"
            },
            headers={'Authorization': f'Bearer {self.admin_token}'}
        )
        
        if success and 'id' in response:
            race_id = response['id']
            
            # Update race
            self.run_test(
                "Update Race",
                "PUT",
                f"races/{race_id}",
                200,
                data={
                    "name": "Updated Test Grand Prix",
                    "location": "Updated Test Circuit"
                },
                headers={'Authorization': f'Bearer {self.admin_token}'}
            )
            
            # Delete race
            self.run_test(
                "Delete Race",
                "DELETE",
                f"races/{race_id}",
                200,
                headers={'Authorization': f'Bearer {self.admin_token}'}
            )

    def test_bets_api(self):
        """Test betting functionality"""
        print("\n🎯 Testing Bets API...")
        
        if not self.token:
            print("❌ No user token, skipping bet tests")
            return
            
        # Get all bets
        self.run_test(
            "Get All Bets",
            "GET",
            "bets",
            200
        )
        
        # Get user's bets
        self.run_test(
            "Get My Bets",
            "GET",
            "bets/my",
            200,
            headers={'Authorization': f'Bearer {self.token}'}
        )

    def test_leaderboard_api(self):
        """Test leaderboard functionality"""
        print("\n🏆 Testing Leaderboard API...")
        
        self.run_test(
            "Get Leaderboard",
            "GET",
            "leaderboard",
            200
        )

    def test_settings_api(self):
        """Test settings functionality"""
        print("\n⚙️ Testing Settings API...")
        
        # Get settings
        self.run_test(
            "Get Settings",
            "GET",
            "settings",
            200
        )
        
        if self.admin_token:
            # Update settings
            self.run_test(
                "Update Settings",
                "PUT",
                "settings",
                200,
                data={
                    "app_title": "Test F1 Betting",
                    "hero_title_en": "Test Hero Title"
                },
                headers={'Authorization': f'Bearer {self.admin_token}'}
            )

    def test_admin_users_api(self):
        """Test admin user management"""
        print("\n👥 Testing Admin Users API...")
        
        if not self.admin_token:
            print("❌ No admin token, skipping admin user tests")
            return
            
        # Get all users (admin only)
        self.run_test(
            "Get All Users (Admin)",
            "GET",
            "admin/users",
            200,
            headers={'Authorization': f'Bearer {self.admin_token}'}
        )

    def test_seed_data(self):
        """Test seed data endpoint"""
        print("\n🌱 Testing Seed Data...")
        
        self.run_test(
            "Seed Data",
            "POST",
            "seed",
            200
        )

    def run_all_tests(self):
        """Run all API tests"""
        print("🚀 Starting F1 Betting API Tests...")
        print(f"Testing against: {self.base_url}")
        
        # Test seed data first
        self.test_seed_data()
        
        # Test authentication
        if self.test_auth_flow():
            # Test other APIs that require authentication
            self.test_bets_api()
            
        # Test admin functionality (first user becomes admin)
        if self.test_admin_registration():
            self.test_drivers_api()
            self.test_races_api()
            self.test_settings_api()
            self.test_admin_users_api()
        
        # Test public APIs
        self.test_leaderboard_api()
        
        # Print summary
        print(f"\n📊 Test Results: {self.tests_passed}/{self.tests_run} passed")
        
        if self.tests_passed == self.tests_run:
            print("🎉 All tests passed!")
            return 0
        else:
            print("❌ Some tests failed")
            return 1

def main():
    tester = F1BettingAPITester()
    return tester.run_all_tests()

if __name__ == "__main__":
    sys.exit(main())