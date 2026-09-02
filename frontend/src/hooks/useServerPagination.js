import { useCallback, useState } from 'react';

export default function useServerPagination() {
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ lastPage: 1, total: 0 });

  const capture = useCallback((response) => {
    if (Array.isArray(response)) {
      setMeta({ lastPage: 1, total: response.length });
      return response;
    }

    setMeta({
      lastPage: Number(response.last_page || 1),
      total: Number(response.total || response.data?.length || 0),
    });
    return response.data || [];
  }, []);

  const resetPage = useCallback(() => setPage(1), []);

  return {
    page,
    setPage,
    lastPage: meta.lastPage,
    total: meta.total,
    requestParams: { page, per_page: 10 },
    capture,
    resetPage,
  };
}
