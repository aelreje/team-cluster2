import { apiFetch } from "./api";

export async function fetchMyRequests() {
  return apiFetch("api/my_requests.php");
}

export async function submitRequest(payload) {
  return apiFetch("api/create_request.php", {
    method: "POST",
    body: JSON.stringify(payload)
  });
}

export function buildRequestHighlights(requests = []) {
  const totals = requests.reduce(
    (acc, item) => {
      const status = String(item.status ?? "").toLowerCase();
      acc.total += 1;
      if (status.includes("pending") || status.includes("endorsed")) acc.pending += 1;
      if (status.includes("approve")) acc.approved += 1;
      if (status.includes("reject") || status.includes("deny")) acc.rejected += 1;
      return acc;
    },
    { total: 0, pending: 0, approved: 0, rejected: 0 }
  );

  return [
    { key: "totalRequests", label: "Total Requests", icon: "🗎", accentClass: "is-slate", value: totals.total, subValue: "All filings" },
    { key: "pendingRequests", label: "Pending", icon: "◷", accentClass: "is-blue", value: totals.pending, subValue: "Awaiting review" },
    { key: "approvedRequests", label: "Approved", icon: "✓", accentClass: "is-green", value: totals.approved, subValue: "Completed" },
    { key: "rejectedRequests", label: "Rejected", icon: "✕", accentClass: "is-red", value: totals.rejected, subValue: "Needs update" }
  ];
}