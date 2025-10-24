CREATE TABLE User (
    id            TEXT PRIMARY KEY,
    full_name     TEXT NOT NULL,
    email         TEXT UNIQUE NOT NULL,
    role          TEXT NOT NULL,
    password      TEXT NOT NULL,
    company_id    TEXT,
    balance       REAL DEFAULT 800.0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (role IN ('user', 'company', 'admin')),
    FOREIGN KEY (company_id) REFERENCES Bus_Company(id)
);

CREATE TABLE Bus_Company (
    id            TEXT PRIMARY KEY,
    name          TEXT UNIQUE NOT NULL,
    logo_path     TEXT,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE Coupons (
    id             TEXT PRIMARY KEY,
    code           TEXT NOT NULL,
    discount       REAL NOT NULL,
    company_id     TEXT,
    usage_limit    INTEGER NOT NULL,
    expire_date    DATETIME NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES Bus_Company(id)
);

CREATE TABLE User_Coupons (
    id             TEXT PRIMARY KEY,
    coupon_id      TEXT,
    user_id        TEXT,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (coupon_id) REFERENCES Coupons(id),
    FOREIGN KEY (user_id) REFERENCES User(id)
);

CREATE TABLE Trips (
    id               TEXT PRIMARY KEY,
    company_id       TEXT NOT NULL,
    destination_city TEXT NOT NULL,
    arrival_time     DATETIME NOT NULL,
    departure_time   DATETIME NOT NULL,
    departure_city   TEXT NOT NULL,
    price            REAL NOT NULL,
    capacity         INTEGER NOT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES Bus_Company(id)
);

CREATE TABLE Tickets (
    id             TEXT PRIMARY KEY,
    trip_id        TEXT NOT NULL,
    user_id        TEXT NOT NULL,
    status         TEXT DEFAULT 'ACTIVE',
    total_price    INTEGER NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trip_id) REFERENCES Trips(id),
    FOREIGN KEY (user_id) REFERENCES User(id)
);

CREATE TABLE Booked_Seats (
    id             TEXT PRIMARY KEY,
    ticket_id      TEXT,
    seat_number    INTEGER,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES Tickets(id)
);

INSERT INTO User(id,full_name,email,role,password,company_id,balance,created_at)
         VALUES ('1337', 'admin', 'adminuser@mail.com', 'admin', '$2y$10$oDey4fbNP/9MT3xT5zawfuRu0e53lHh2W.d1TajDwwz4DUIQk67Q6', NULL, 1337, '2025-10-24 16:32:01'); -- password is adminpass

