import { pg } from "../config/database";
import { createAccessToken, verifyAccessToken } from "../utils/jwt";
import type { RegisterInput } from "../schemas/auth.schema";
import * as dotenv from "dotenv";

// Load environment variables from .env file
dotenv.config();

export async function login(identifiant: string, password: string) {
    const [user] = await pg`
        SELECT id, email, password, identifiant
        FROM users.users
        WHERE identifiant = ${identifiant}
        LIMIT 1
    `;

    if (!user) {
        return null;
    }

    const passwordValid = await Bun.password.verify(
        password,
        user.password
    );

    if (!passwordValid) {
        return null;
    }

    const accessToken = await createAccessToken(String(user.id));

    return {
        accessToken,
    };
}

export async function register({ name, prenom, email, password }: RegisterInput) {
    if (email !== null) {
        const [existingUser] = await pg`
        SELECT id
        FROM users.users
        WHERE email = ${email}
        LIMIT 1
    `;

        if (existingUser) {
            throw new Error("EMAIL_ALREADY_EXISTS");
        }
    }

    // Hasher le mot de password en second temps
    const hashed = await Bun.password.hash(password);
    const firstname = prenom.trim().charAt(0).toLowerCase();
    const lastname = name.toLowerCase().split(" ").join("");

    const identifiant = firstname + "." + lastname

    // Docs: racourcie pg dans bun
    // INSERT : les clés de l'objet deviennent les colonnes
    // await pg`INSERT INTO users ${pg({ email, password_hash: hash })}`;
    // // Plusieurs lignes d'un coup : un tableau d'objets
    // await pg`INSERT INTO users ${pg(nouveauxComptes)}`;
    // // UPDATE : seulement les champs présents dans l'objet
    // await pg`UPDATE users SET ${pg(modifs)} WHERE id = ${id}`;
    // // WHERE ... IN
    // await pg`SELECT * FROM users WHERE id IN ${pg([1, 2, 3])}`;
    const [user] = await pg`INSERT INTO users.users ${pg({ name: name, password: hashed, email: email, identifiant: identifiant, prenom: prenom })} RETURNING id, identifiant`;

    console.log(user)
    return {
        message: "Users créé !",
        user: user,
        status: 200
    }
}

export async function getUserById(userID: number) {
    const [user] = await pg`
        SELECT id, identifiant, email, name, prenom
        FROM users.users
        WHERE id = ${userID}
        LIMIT 1
    `;

    return user;
}