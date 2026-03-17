import { useState, useEffect, useCallback } from 'react';
import { fetchAttendanceHistory } from '../api/attendance';

export const useAttendanceHistory = () => {
  const [data, setData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const response = await fetchAttendanceHistory();
      if (response.error) throw new Error(response.error);
      setData(response);
      setError(null);
    } catch (err) {
      setError(err.message || 'Failed to load attendance records');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  return { data, loading, error, refetch: loadData };
};
