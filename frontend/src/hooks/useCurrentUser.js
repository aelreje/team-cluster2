import { useEffect, useState } from "react";
import { apiFetch } from "../api/api";

const STORAGE_KEY = "teamClusterUser";

const readStoredUser = () => {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    return JSON.parse(raw);
  } catch (error) {
    console.warn("Unable to read stored user", error);
    return null;
  }
};

export default function useCurrentUser() {
  const [user, setUser] = useState(() => readStoredUser());
  const [loading, setLoading] = useState(!user);

  useEffect(() => {
    let isActive = true;

    const fetchUser = async () => {
      try {
        const response = await apiFetch("auth/me.php");
        if (!isActive) return;
        setUser(response);
        localStorage.setItem(
          STORAGE_KEY,
          JSON.stringify({
            fullname: response.fullname,
            role: response.role,
            permissions: Array.isArray(response.permissions) ? response.permissions : []
          })
        );
      } catch {
        if (!isActive) return;
        setUser(null);
      } finally {
        if (isActive) setLoading(false);
      }
    };

    if (!user) {
      fetchUser();
    } else {
      setLoading(false);
    }

    return () => {
      isActive = false;
    };
  }, [user]);

  return { user, loading };
}
