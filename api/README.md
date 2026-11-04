# API Web Idem

Ceci et une api web, réaliser avec bun, zod et jose. elle et particuliérement perfomante, et trés facile a lire.

# Comment protéger une route ?

Pour protéger votre route utiliser les deux lignes suivantes :

```typescript
const userId = await getUserById(req);
if (!userId) return Response.json({ error: "Unauthorized" }, { status: 401 });
```

> NOTE
> Oublier pas de importer la fonction getUserById.