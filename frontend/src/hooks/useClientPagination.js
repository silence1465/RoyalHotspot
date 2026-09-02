import { useEffect, useMemo, useState } from 'react';

export default function useClientPagination(items, perPage = 10) {
  const [page, setPage] = useState(1);
  const total = items?.length || 0;
  const lastPage = Math.max(1, Math.ceil(total / perPage));

  useEffect(() => {
    if (page > lastPage) setPage(lastPage);
  }, [page, lastPage]);

  const rows = useMemo(
    () => (items || []).slice((page - 1) * perPage, page * perPage),
    [items, page, perPage],
  );

  return { rows, page, setPage, lastPage, total };
}
