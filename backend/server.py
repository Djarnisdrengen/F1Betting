from fastapi import FastAPI, APIRouter, HTTPException, Depends, status
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials
from dotenv import load_dotenv
from starlette.middleware.cors import CORSMiddleware
from motor.motor_asyncio import AsyncIOMotorClient
import os
import logging
from pathlib import Path
from pydantic import BaseModel, Field, EmailStr
from typing import List, Optional
import uuid
from datetime import datetime, timezone, timedelta
import jwt
import bcrypt

ROOT_DIR = Path(__file__).parent
load_dotenv(ROOT_DIR / '.env')

# MongoDB connection
mongo_url = os.environ['MONGO_URL']
client = AsyncIOMotorClient(mongo_url)
db = client[os.environ['DB_NAME']]

app = FastAPI()
api_router = APIRouter(prefix="/api")
security = HTTPBearer()

JWT_SECRET = os.environ['JWT_SECRET']
JWT_ALGORITHM = "HS256"

# ==================== MODELS ====================

class PasswordResetRequest(BaseModel):
    email: EmailStr

class PasswordReset(BaseModel):
    token: str
    password: str

class UserCreate(BaseModel):
    email: EmailStr
    password: str
    display_name: Optional[str] = None

class UserLogin(BaseModel):
    email: EmailStr
    password: str

class UserUpdate(BaseModel):
    display_name: Optional[str] = None

class UserResponse(BaseModel):
    id: str
    email: str
    display_name: Optional[str] = None
    role: str = "user"
    stars: int = 0
    points: int = 0
    created_at: str

class DriverCreate(BaseModel):
    name: str
    team: str
    number: int

class DriverResponse(BaseModel):
    id: str
    name: str
    team: str
    number: int

class RaceCreate(BaseModel):
    name: str
    location: str
    race_date: str
    race_time: str
    quali_p1: Optional[str] = None
    quali_p2: Optional[str] = None
    quali_p3: Optional[str] = None

class RaceUpdate(BaseModel):
    name: Optional[str] = None
    location: Optional[str] = None
    race_date: Optional[str] = None
    race_time: Optional[str] = None
    quali_p1: Optional[str] = None
    quali_p2: Optional[str] = None
    quali_p3: Optional[str] = None
    result_p1: Optional[str] = None
    result_p2: Optional[str] = None
    result_p3: Optional[str] = None

class RaceResponse(BaseModel):
    id: str
    name: str
    location: str
    race_date: str
    race_time: str
    quali_p1: Optional[str] = None
    quali_p2: Optional[str] = None
    quali_p3: Optional[str] = None
    result_p1: Optional[str] = None
    result_p2: Optional[str] = None
    result_p3: Optional[str] = None

class BetCreate(BaseModel):
    race_id: str
    p1: str
    p2: str
    p3: str

class BetResponse(BaseModel):
    id: str
    user_id: str
    user_display_name: Optional[str] = None
    user_email: str
    race_id: str
    p1: str
    p2: str
    p3: str
    points: int = 0
    is_perfect: bool = False
    placed_at: str

class SettingsUpdate(BaseModel):
    app_title: Optional[str] = None
    app_year: Optional[str] = None
    hero_title_en: Optional[str] = None
    hero_title_da: Optional[str] = None
    hero_text_en: Optional[str] = None
    hero_text_da: Optional[str] = None

class SettingsResponse(BaseModel):
    id: str
    app_title: str
    app_year: str
    hero_title_en: str
    hero_title_da: str
    hero_text_en: str
    hero_text_da: str

class LeaderboardEntry(BaseModel):
    user_id: str
    display_name: Optional[str] = None
    email: str
    points: int
    stars: int
    bets_count: int

# ==================== AUTH HELPERS ====================

def hash_password(password: str) -> str:
    return bcrypt.hashpw(password.encode('utf-8'), bcrypt.gensalt()).decode('utf-8')

def verify_password(password: str, hashed: str) -> bool:
    return bcrypt.checkpw(password.encode('utf-8'), hashed.encode('utf-8'))

def create_token(user_id: str, role: str) -> str:
    payload = {
        "user_id": user_id,
        "role": role,
        "exp": datetime.now(timezone.utc) + timedelta(days=7)
    }
    return jwt.encode(payload, JWT_SECRET, algorithm=JWT_ALGORITHM)

async def get_current_user(credentials: HTTPAuthorizationCredentials = Depends(security)):
    try:
        payload = jwt.decode(credentials.credentials, JWT_SECRET, algorithms=[JWT_ALGORITHM])
        user = await db.users.find_one({"id": payload["user_id"]}, {"_id": 0})
        if not user:
            raise HTTPException(status_code=401, detail="User not found")
        return user
    except jwt.ExpiredSignatureError:
        raise HTTPException(status_code=401, detail="Token expired")
    except jwt.InvalidTokenError:
        raise HTTPException(status_code=401, detail="Invalid token")

async def get_admin_user(current_user: dict = Depends(get_current_user)):
    if current_user.get("role") != "admin":
        raise HTTPException(status_code=403, detail="Admin access required")
    return current_user

# ==================== AUTH ROUTES ====================

@api_router.post("/auth/register")
async def register(user: UserCreate):
    existing = await db.users.find_one({"email": user.email})
    if existing:
        raise HTTPException(status_code=400, detail="Email already registered")
    
    user_count = await db.users.count_documents({})
    user_doc = {
        "id": str(uuid.uuid4()),
        "email": user.email,
        "password": hash_password(user.password),
        "display_name": user.display_name or user.email.split("@")[0],
        "role": "admin" if user_count == 0 else "user",
        "stars": 0,
        "points": 0,
        "created_at": datetime.now(timezone.utc).isoformat()
    }
    await db.users.insert_one(user_doc)
    token = create_token(user_doc["id"], user_doc["role"])
    return {"token": token, "user": {k: v for k, v in user_doc.items() if k not in ["password", "_id"]}}

@api_router.post("/auth/login")
async def login(user: UserLogin):
    db_user = await db.users.find_one({"email": user.email})
    if not db_user or not verify_password(user.password, db_user["password"]):
        raise HTTPException(status_code=401, detail="Invalid credentials")
    
    token = create_token(db_user["id"], db_user["role"])
    return {"token": token, "user": {k: v for k, v in db_user.items() if k not in ["password", "_id"]}}

@api_router.get("/auth/me")
async def get_me(current_user: dict = Depends(get_current_user)):
    return {k: v for k, v in current_user.items() if k != "password"}

@api_router.put("/auth/profile")
async def update_profile(update: UserUpdate, current_user: dict = Depends(get_current_user)):
    update_data = {k: v for k, v in update.model_dump().items() if v is not None}
    if update_data:
        await db.users.update_one({"id": current_user["id"]}, {"$set": update_data})
    updated = await db.users.find_one({"id": current_user["id"]}, {"_id": 0, "password": 0})
    return updated

# ==================== DRIVER ROUTES ====================

@api_router.get("/drivers", response_model=List[DriverResponse])
async def get_drivers():
    drivers = await db.drivers.find({}, {"_id": 0}).to_list(100)
    return drivers

@api_router.post("/drivers", response_model=DriverResponse)
async def create_driver(driver: DriverCreate, admin: dict = Depends(get_admin_user)):
    driver_doc = {
        "id": str(uuid.uuid4()),
        **driver.model_dump()
    }
    await db.drivers.insert_one(driver_doc)
    return {k: v for k, v in driver_doc.items() if k != "_id"}

@api_router.put("/drivers/{driver_id}", response_model=DriverResponse)
async def update_driver(driver_id: str, driver: DriverCreate, admin: dict = Depends(get_admin_user)):
    await db.drivers.update_one({"id": driver_id}, {"$set": driver.model_dump()})
    updated = await db.drivers.find_one({"id": driver_id}, {"_id": 0})
    if not updated:
        raise HTTPException(status_code=404, detail="Driver not found")
    return updated

@api_router.delete("/drivers/{driver_id}")
async def delete_driver(driver_id: str, admin: dict = Depends(get_admin_user)):
    result = await db.drivers.delete_one({"id": driver_id})
    if result.deleted_count == 0:
        raise HTTPException(status_code=404, detail="Driver not found")
    return {"message": "Driver deleted"}

# ==================== RACE ROUTES ====================

@api_router.get("/races", response_model=List[RaceResponse])
async def get_races():
    races = await db.races.find({}, {"_id": 0}).to_list(100)
    return races

@api_router.post("/races", response_model=RaceResponse)
async def create_race(race: RaceCreate, admin: dict = Depends(get_admin_user)):
    race_doc = {
        "id": str(uuid.uuid4()),
        **race.model_dump(),
        "result_p1": None,
        "result_p2": None,
        "result_p3": None
    }
    await db.races.insert_one(race_doc)
    return {k: v for k, v in race_doc.items() if k != "_id"}

@api_router.put("/races/{race_id}", response_model=RaceResponse)
async def update_race(race_id: str, race: RaceUpdate, admin: dict = Depends(get_admin_user)):
    update_data = {k: v for k, v in race.model_dump().items() if v is not None}
    if update_data:
        await db.races.update_one({"id": race_id}, {"$set": update_data})
    
    updated = await db.races.find_one({"id": race_id}, {"_id": 0})
    if not updated:
        raise HTTPException(status_code=404, detail="Race not found")
    
    # Calculate points for all bets when results are set
    if race.result_p1 and race.result_p2 and race.result_p3:
        await calculate_race_points(race_id, race.result_p1, race.result_p2, race.result_p3)
    
    return updated

@api_router.delete("/races/{race_id}")
async def delete_race(race_id: str, admin: dict = Depends(get_admin_user)):
    result = await db.races.delete_one({"id": race_id})
    if result.deleted_count == 0:
        raise HTTPException(status_code=404, detail="Race not found")
    await db.bets.delete_many({"race_id": race_id})
    return {"message": "Race deleted"}

async def calculate_race_points(race_id: str, p1: str, p2: str, p3: str):
    bets = await db.bets.find({"race_id": race_id}).to_list(1000)
    results = [p1, p2, p3]
    
    for bet in bets:
        old_points = bet.get("points", 0)
        old_is_perfect = bet.get("is_perfect", False)
        
        points = 0
        predictions = [bet["p1"], bet["p2"], bet["p3"]]
        
        # Exact position points: P1=25, P2=18, P3=15
        if bet["p1"] == p1:
            points += 25
        if bet["p2"] == p2:
            points += 18
        if bet["p3"] == p3:
            points += 15
        
        # Bonus for drivers in top 3 but wrong position
        for pred in predictions:
            if pred in results and predictions.index(pred) != results.index(pred):
                points += 5
        
        is_perfect = (bet["p1"] == p1 and bet["p2"] == p2 and bet["p3"] == p3)
        
        await db.bets.update_one(
            {"id": bet["id"]},
            {"$set": {"points": points, "is_perfect": is_perfect}}
        )
        
        # Update user points and stars (subtract old, add new to avoid duplicates)
        user = await db.users.find_one({"id": bet["user_id"]})
        if user:
            current_points = user.get("points", 0)
            current_stars = user.get("stars", 0)
            
            # Remove old points/stars, add new ones
            new_points = current_points - old_points + points
            new_stars = current_stars - (1 if old_is_perfect else 0) + (1 if is_perfect else 0)
            
            await db.users.update_one(
                {"id": bet["user_id"]},
                {"$set": {"points": max(0, new_points), "stars": max(0, new_stars)}}
            )

# ==================== BET ROUTES ====================

@api_router.get("/bets", response_model=List[BetResponse])
async def get_all_bets():
    bets = await db.bets.find({}, {"_id": 0}).to_list(1000)
    # Batch fetch all users to avoid N+1 queries
    user_ids = list(set(bet["user_id"] for bet in bets))
    users_list = await db.users.find({"id": {"$in": user_ids}}, {"_id": 0, "password": 0}).to_list(len(user_ids))
    users_map = {u["id"]: u for u in users_list}
    for bet in bets:
        user = users_map.get(bet["user_id"])
        if user:
            bet["user_display_name"] = user.get("display_name")
            bet["user_email"] = user.get("email", "")
    return bets

@api_router.get("/bets/race/{race_id}", response_model=List[BetResponse])
async def get_bets_by_race(race_id: str):
    bets = await db.bets.find({"race_id": race_id}, {"_id": 0}).to_list(1000)
    # Batch fetch users to avoid N+1 queries
    user_ids = list(set(bet["user_id"] for bet in bets))
    if user_ids:
        users_list = await db.users.find({"id": {"$in": user_ids}}, {"_id": 0, "password": 0}).to_list(len(user_ids))
        users_map = {u["id"]: u for u in users_list}
        for bet in bets:
            user = users_map.get(bet["user_id"])
            if user:
                bet["user_display_name"] = user.get("display_name")
                bet["user_email"] = user.get("email", "")
    return bets

@api_router.get("/bets/my", response_model=List[BetResponse])
async def get_my_bets(current_user: dict = Depends(get_current_user)):
    bets = await db.bets.find({"user_id": current_user["id"]}, {"_id": 0}).to_list(100)
    for bet in bets:
        bet["user_display_name"] = current_user.get("display_name")
        bet["user_email"] = current_user.get("email", "")
    return bets

@api_router.post("/bets", response_model=BetResponse)
async def create_bet(bet: BetCreate, current_user: dict = Depends(get_current_user)):
    race = await db.races.find_one({"id": bet.race_id}, {"_id": 0})
    if not race:
        raise HTTPException(status_code=404, detail="Race not found")
    
    # Check betting window (48h before race until race start)
    race_datetime = datetime.fromisoformat(f"{race['race_date']}T{race['race_time']}:00")
    race_datetime = race_datetime.replace(tzinfo=timezone.utc)
    now = datetime.now(timezone.utc)
    betting_opens = race_datetime - timedelta(hours=48)
    
    if now < betting_opens:
        raise HTTPException(status_code=400, detail="Betting has not opened yet (opens 48h before race)")
    if now >= race_datetime:
        raise HTTPException(status_code=400, detail="Betting is closed (race has started)")
    
    # Check for duplicate selections in same bet
    if len(set([bet.p1, bet.p2, bet.p3])) != 3:
        raise HTTPException(status_code=400, detail="Cannot select same driver multiple times")
    
    # Check if bet matches qualification results
    if race.get("quali_p1") and race.get("quali_p2") and race.get("quali_p3"):
        if bet.p1 == race["quali_p1"] and bet.p2 == race["quali_p2"] and bet.p3 == race["quali_p3"]:
            raise HTTPException(status_code=400, detail="Prediction cannot match qualifying results exactly")
    
    # Check if user already has a bet for this race
    existing = await db.bets.find_one({"user_id": current_user["id"], "race_id": bet.race_id})
    if existing:
        raise HTTPException(status_code=400, detail="You already have a bet for this race")
    
    # Check if exact prediction is already taken
    existing_combo = await db.bets.find_one({
        "race_id": bet.race_id,
        "p1": bet.p1,
        "p2": bet.p2,
        "p3": bet.p3
    })
    if existing_combo:
        raise HTTPException(status_code=400, detail="This exact prediction is already taken by another user")
    
    bet_doc = {
        "id": str(uuid.uuid4()),
        "user_id": current_user["id"],
        "race_id": bet.race_id,
        "p1": bet.p1,
        "p2": bet.p2,
        "p3": bet.p3,
        "points": 0,
        "is_perfect": False,
        "placed_at": datetime.now(timezone.utc).isoformat(),
        "user_display_name": current_user.get("display_name"),
        "user_email": current_user.get("email", "")
    }
    await db.bets.insert_one(bet_doc)
    return {k: v for k, v in bet_doc.items() if k != "_id"}

@api_router.delete("/bets/{bet_id}")
async def delete_bet(bet_id: str, current_user: dict = Depends(get_current_user)):
    bet = await db.bets.find_one({"id": bet_id})
    if not bet:
        raise HTTPException(status_code=404, detail="Bet not found")
    
    if bet["user_id"] != current_user["id"] and current_user.get("role") != "admin":
        raise HTTPException(status_code=403, detail="Not authorized to delete this bet")
    
    await db.bets.delete_one({"id": bet_id})
    return {"message": "Bet deleted"}

# ==================== LEADERBOARD ====================

@api_router.get("/leaderboard", response_model=List[LeaderboardEntry])
async def get_leaderboard():
    users = await db.users.find({}, {"_id": 0, "password": 0}).to_list(1000)
    
    # Use aggregation to count bets per user in a single query
    pipeline = [{"$group": {"_id": "$user_id", "count": {"$sum": 1}}}]
    bet_counts_list = await db.bets.aggregate(pipeline).to_list(None)
    bet_counts = {item["_id"]: item["count"] for item in bet_counts_list}
    
    leaderboard = []
    for user in users:
        leaderboard.append({
            "user_id": user["id"],
            "display_name": user.get("display_name"),
            "email": user.get("email", ""),
            "points": user.get("points", 0),
            "stars": user.get("stars", 0),
            "bets_count": bet_counts.get(user["id"], 0)
        })
    
    leaderboard.sort(key=lambda x: (-x["points"], -x["stars"]))
    return leaderboard

# ==================== SETTINGS ====================

@api_router.get("/settings", response_model=SettingsResponse)
async def get_settings():
    settings = await db.settings.find_one({}, {"_id": 0})
    if not settings:
        default = {
            "id": str(uuid.uuid4()),
            "app_title": "F1 Betting",
            "app_year": "2025",
            "hero_title_en": "Predict the Podium",
            "hero_title_da": "Forudsig Podiet",
            "hero_text_en": "Compete with friends by predicting top 3 for each Grand Prix. Earn points for correct predictions.",
            "hero_text_da": "Konkurrér med venner ved at forudsige top 3 for hvert Grand Prix. Optjen point for korrekte forudsigelser."
        }
        await db.settings.insert_one(default)
        return default
    return settings

@api_router.put("/settings", response_model=SettingsResponse)
async def update_settings(settings: SettingsUpdate, admin: dict = Depends(get_admin_user)):
    update_data = {k: v for k, v in settings.model_dump().items() if v is not None}
    if update_data:
        await db.settings.update_one({}, {"$set": update_data})
    updated = await db.settings.find_one({}, {"_id": 0})
    return updated

# ==================== ADMIN USER MANAGEMENT ====================

@api_router.get("/admin/users", response_model=List[UserResponse])
async def get_all_users(admin: dict = Depends(get_admin_user)):
    users = await db.users.find({}, {"_id": 0, "password": 0}).to_list(1000)
    return users

@api_router.put("/admin/users/{user_id}/role")
async def update_user_role(user_id: str, role: str, admin: dict = Depends(get_admin_user)):
    if role not in ["user", "admin"]:
        raise HTTPException(status_code=400, detail="Invalid role")
    await db.users.update_one({"id": user_id}, {"$set": {"role": role}})
    return {"message": "Role updated"}

@api_router.delete("/admin/users/{user_id}")
async def delete_user(user_id: str, admin: dict = Depends(get_admin_user)):
    if user_id == admin["id"]:
        raise HTTPException(status_code=400, detail="Cannot delete yourself")
    result = await db.users.delete_one({"id": user_id})
    if result.deleted_count == 0:
        raise HTTPException(status_code=404, detail="User not found")
    await db.bets.delete_many({"user_id": user_id})
    return {"message": "User deleted"}

# ==================== SEED DATA ====================

@api_router.post("/seed")
async def seed_data():
    # Check if data already exists
    drivers_count = await db.drivers.count_documents({})
    if drivers_count > 0:
        return {"message": "Data already seeded"}
    
    # Seed drivers
    drivers = [
        {"id": str(uuid.uuid4()), "name": "Max Verstappen", "team": "Red Bull Racing", "number": 1},
        {"id": str(uuid.uuid4()), "name": "Sergio Perez", "team": "Red Bull Racing", "number": 11},
        {"id": str(uuid.uuid4()), "name": "Lewis Hamilton", "team": "Ferrari", "number": 44},
        {"id": str(uuid.uuid4()), "name": "Charles Leclerc", "team": "Ferrari", "number": 16},
        {"id": str(uuid.uuid4()), "name": "Lando Norris", "team": "McLaren", "number": 4},
        {"id": str(uuid.uuid4()), "name": "Oscar Piastri", "team": "McLaren", "number": 81},
        {"id": str(uuid.uuid4()), "name": "George Russell", "team": "Mercedes", "number": 63},
        {"id": str(uuid.uuid4()), "name": "Andrea Kimi Antonelli", "team": "Mercedes", "number": 12},
        {"id": str(uuid.uuid4()), "name": "Fernando Alonso", "team": "Aston Martin", "number": 14},
        {"id": str(uuid.uuid4()), "name": "Lance Stroll", "team": "Aston Martin", "number": 18},
    ]
    await db.drivers.insert_many(drivers)
    
    # Seed races
    races = [
        {
            "id": str(uuid.uuid4()),
            "name": "Australian Grand Prix",
            "location": "Melbourne",
            "race_date": "2025-03-16",
            "race_time": "05:00",
            "quali_p1": drivers[0]["id"],
            "quali_p2": drivers[4]["id"],
            "quali_p3": drivers[2]["id"],
            "result_p1": None, "result_p2": None, "result_p3": None
        },
        {
            "id": str(uuid.uuid4()),
            "name": "Monaco Grand Prix",
            "location": "Monte Carlo",
            "race_date": "2025-05-25",
            "race_time": "14:00",
            "quali_p1": drivers[3]["id"],
            "quali_p2": drivers[0]["id"],
            "quali_p3": drivers[4]["id"],
            "result_p1": None, "result_p2": None, "result_p3": None
        },
        {
            "id": str(uuid.uuid4()),
            "name": "British Grand Prix",
            "location": "Silverstone",
            "race_date": "2025-07-06",
            "race_time": "15:00",
            "quali_p1": None,
            "quali_p2": None,
            "quali_p3": None,
            "result_p1": None, "result_p2": None, "result_p3": None
        }
    ]
    await db.races.insert_many(races)
    
    return {"message": "Data seeded successfully", "drivers": len(drivers), "races": len(races)}

# Include router and middleware
app.include_router(api_router)

app.add_middleware(
    CORSMiddleware,
    allow_credentials=True,
    allow_origins=os.environ.get('CORS_ORIGINS', '*').split(','),
    allow_methods=["*"],
    allow_headers=["*"],
)

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(name)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

@app.on_event("shutdown")
async def shutdown_db_client():
    client.close()
