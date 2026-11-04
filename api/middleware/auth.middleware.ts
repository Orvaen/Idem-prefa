import { errors } from "jose";
import { getUserById } from "../services/auth.service";
import { verifyAccessToken } from "../utils/jwt";

export async function authentificate(req: Request) {
    // Récuperer le authorization
    const auth = req.headers.get("authorization")

    // Récupérer le Beader et lancer un erreure si la variable et null
    if (!auth || !auth.startsWith("Bearer ")) {
        return {
            error: Response.json(
                { error: "Missing access token" },
                { status: 401 }
            )
        }
    }

    const token = auth.slice(7).trim(); // prendre les 7 premiéres lettre de Beare

    try {
        const payload = await verifyAccessToken(token);
        if (!payload.sub) {
            return {
                error: Response.json(
                    { error: "Invalid Token" },
                    { status: 401 }
                )
            }
        }

        const user = await getUserById(Number(payload.sub));

        if(!user){
            return {
                error: Response.json(
                    {error: "User not found"},
                    {status: 401}
                )
            }
        }

        return user;
    } catch (err) {
         if (err instanceof errors.JWTExpired) {
            return {
                error: Response.json(
                    { error: "Token expired" },
                    { status: 401 }
                )
            };
        }

        return {
            error: Response.json(
                { error: "Invalid token" },
                { status: 401 }
            )
        };
    }
}