import {z} from "zod";

export const loginSchema = z.object({
    identifiant: z.string(),
    password: z.string(),
})

export const RegisterSchema = z.object({
    name: z.string(),
    prenom: z.string(),
    email: z.string().nullable(),
    password: z.string(),
})

export const LoginResponseSchema = z.object({
    accessToken: z.string(),
})

export type LoginInput = z.infer<typeof loginSchema>;
export type RegisterInput = z.infer<typeof RegisterSchema>