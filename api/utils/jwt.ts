import { SignJWT, jwtVerify } from "jose";

const secret = process.env.JWT_SECRET;

if (!secret || secret.length < 32) {
    throw new Error("JWT_SECRET must contain at least 32 characters");
}

const secretKey = new TextEncoder().encode(secret);

export async function createAccessToken(userId: string) {
    return new SignJWT({
        sub: userId,
    })
        .setProtectedHeader({ alg: "HS256" })
        .setIssuedAt()
        .setExpirationTime("2h") // expiration du jeton => format : 15m, 2h
        .sign(secretKey);
}

export async function verifyAccessToken(token: string) {
    const { payload } = await jwtVerify(token, secretKey, {
        algorithms: ["HS256"],
    });

    return payload;
}
